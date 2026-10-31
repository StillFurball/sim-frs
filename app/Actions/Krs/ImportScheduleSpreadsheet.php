<?php

namespace App\Actions\Krs;

use App\Enums\CourseType;
use App\Enums\Day;
use App\Exceptions\ScheduleImportException;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Lecturer;
use App\Support\ScheduleImportResult;
use App\Support\XlsxReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import the classes of one academic period, plus their courses and lecturers, from a schedule spreadsheet (.xlsx or .csv).
 *
 * The header row is detected automatically, so title rows above it and summary rows below the table are ignored.
 * Courses are a catalogue shared by every period; classes are matched on period + course + class name + prodi, which
 * makes repeated imports into the same period update rather than duplicate.
 */
class ImportScheduleSpreadsheet
{
    /**
     * Spreadsheet header aliases (normalized to lowercase alphanumerics) for each recognised column.
     *
     * @var array<string, list<string>>
     */
    private const array COLUMN_ALIASES = [
        'semester' => ['semester', 'smt', 'sem'],
        'code' => ['kodemk', 'kode', 'kodematakuliah', 'kodematkul'],
        'name' => ['matakuliah', 'namamatakuliah', 'namamk', 'matkul', 'namamatkul'],
        'class' => ['kelas', 'namakelas'],
        'prodi' => ['prodi', 'programstudi'],
        'type' => ['jenis', 'jenismk', 'tipe', 'sifat', 'kategori'],
        'sks' => ['sks'],
        'lecturers' => ['dosenkode', 'dosen', 'kodedosen', 'pengajar', 'dosenpengampu'],
        'day' => ['hari'],
        'start' => ['mulai', 'jammulai', 'waktumulai'],
        'end' => ['selesai', 'jamselesai', 'waktuselesai'],
        'time' => ['jam', 'waktu'],
        'room' => ['ruang', 'ruangan'],
        'capacity' => ['kapasitas', 'kuota', 'dayatampung'],
        'language' => ['bahasa', 'bahasapengantar'],
        'ramadhan' => ['jadwalramadhan', 'ramadhan'],
    ];

    /**
     * Number of leading rows searched for the header row.
     */
    private const int HEADER_SEARCH_ROWS = 30;

    public function __construct(private XlsxReader $reader) {}

    /**
     * Import the spreadsheet at the given path into the academic period.
     *
     * @param  bool  $replaceExisting  Delete the period's classes (and the enrollments in them) before importing.
     *
     * @throws ScheduleImportException
     */
    public function handle(string $path, AcademicPeriod $period, bool $replaceExisting = false, ?string $extension = null): ScheduleImportResult
    {
        $extension = Str::lower($extension ?? pathinfo($path, PATHINFO_EXTENSION));
        $rows = $extension === 'csv' ? $this->csvRows($path) : $this->reader->rows($path);

        [$headerIndex, $columns] = $this->locateHeader($rows);

        return DB::transaction(function () use ($rows, $headerIndex, $columns, $period, $replaceExisting): ScheduleImportResult {
            if ($replaceExisting) {
                CourseClass::query()->inPeriod($period)->delete();
            }

            $coursesCreated = 0;
            $classesCreated = 0;
            $classesUpdated = 0;
            $rowsSkipped = 0;
            $errors = [];

            for ($index = $headerIndex + 1, $total = count($rows); $index < $total; $index++) {
                $rowNumber = $index + 1;
                $values = $this->extractValues($rows[$index], $columns);

                if ($this->isNotScheduleRow($values)) {
                    continue;
                }

                $error = $this->validateRow($values);

                if ($error !== null) {
                    $errors[] = "Baris {$rowNumber}: {$error}";
                    $rowsSkipped++;

                    continue;
                }

                $course = $this->upsertCourse($values, $error);

                if ($course === null) {
                    $errors[] = "Baris {$rowNumber}: {$error}";
                    $rowsSkipped++;

                    continue;
                }

                if ($course->wasRecentlyCreated) {
                    $coursesCreated++;
                }

                $class = $this->upsertClass($period, $course, $values);
                $class->wasRecentlyCreated ? $classesCreated++ : $classesUpdated++;
            }

            if ($classesCreated + $classesUpdated === 0) {
                throw new ScheduleImportException(
                    $errors === []
                        ? 'Tidak ada baris jadwal yang dapat diimpor.'
                        : 'Tidak ada baris jadwal yang valid. '.implode(' ', array_slice($errors, 0, 3)),
                );
            }

            return new ScheduleImportResult($coursesCreated, $classesCreated, $classesUpdated, $rowsSkipped, $errors);
        });
    }

    /**
     * Read a CSV file into rows, detecting comma or semicolon delimiters.
     *
     * @return list<list<string|null>>
     *
     * @throws ScheduleImportException
     */
    private function csvRows(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new ScheduleImportException('File CSV tidak dapat dibaca.');
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $sample = implode("\n", array_slice($lines, 0, self::HEADER_SEARCH_ROWS));
        $delimiter = substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';

        $rows = [];

        foreach ($lines as $line) {
            $rows[] = array_map(
                fn (?string $value): ?string => ($value = trim((string) $value)) === '' ? null : $value,
                str_getcsv($line, $delimiter, '"', ''),
            );
        }

        return $rows;
    }

    /**
     * Find the header row and map each recognised column to its index.
     *
     * @param  list<list<string|null>>  $rows
     * @return array{0: int, 1: array<string, int>}
     *
     * @throws ScheduleImportException
     */
    private function locateHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, self::HEADER_SEARCH_ROWS) as $index => $row) {
            $columns = [];

            foreach ($row as $column => $value) {
                $key = $this->columnKey($value);

                if ($key !== null && ! isset($columns[$key])) {
                    $columns[$key] = $column;
                }
            }

            if (isset($columns['name'], $columns['day'])) {
                $missing = array_diff(['sks'], array_keys($columns));

                if (! isset($columns['time']) && ! isset($columns['start'], $columns['end'])) {
                    $missing[] = 'mulai/selesai';
                }

                if ($missing !== []) {
                    throw new ScheduleImportException('Kolom wajib tidak ditemukan: '.implode(', ', $missing).'.');
                }

                return [$index, $columns];
            }
        }

        throw new ScheduleImportException('Baris header tidak ditemukan. Pastikan ada kolom "Mata Kuliah", "Hari", "SKS", "Mulai" dan "Selesai".');
    }

    /**
     * Resolve which logical column a header cell represents.
     */
    private function columnKey(?string $header): ?string
    {
        $normalized = preg_replace('/\(.*?\)/', '', Str::lower((string) $header)) ?? '';
        $normalized = preg_replace('/[^a-z0-9]/', '', $normalized) ?? '';

        if ($normalized === '') {
            return null;
        }

        foreach (self::COLUMN_ALIASES as $key => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Pull the raw values of a data row into named keys.
     *
     * @param  list<string|null>  $row
     * @param  array<string, int>  $columns
     * @return array<string, string|null>
     */
    private function extractValues(array $row, array $columns): array
    {
        $values = [];

        foreach (array_keys(self::COLUMN_ALIASES) as $key) {
            $value = isset($columns[$key]) ? ($row[$columns[$key]] ?? null) : null;
            $values[$key] = $value === null || trim($value) === '' ? null : trim($value);
        }

        if (isset($values['time']) && ($values['start'] === null || $values['end'] === null)) {
            $parts = preg_split('/\s*[-–—]\s*|\s+s\.?d\.?\s+/u', $values['time']) ?: [];

            if (count($parts) === 2) {
                $values['start'] ??= $parts[0];
                $values['end'] ??= $parts[1];
            }
        }

        return $values;
    }

    /**
     * Determine whether a row is blank or a summary/note line rather than a scheduled class.
     *
     * @param  array<string, string|null>  $values
     */
    private function isNotScheduleRow(array $values): bool
    {
        return $values['name'] === null
            || ($values['day'] === null && $values['start'] === null && $values['sks'] === null);
    }

    /**
     * Validate and normalise a data row in place. Returns an error message when the row cannot be imported.
     *
     * @param  array<string, string|null>  $values
     */
    private function validateRow(array &$values): ?string
    {
        if (Day::fromLabel($values['day']) === null) {
            return "hari '{$values['day']}' tidak dikenali.";
        }

        $start = $this->normalizeTime($values['start']);
        $end = $this->normalizeTime($values['end']);

        if ($start === null || $end === null) {
            return "jam '{$values['start']}' – '{$values['end']}' tidak valid.";
        }

        if ($start >= $end) {
            return "jam selesai ({$end}) harus setelah jam mulai ({$start}).";
        }

        $sks = filter_var($values['sks'], FILTER_VALIDATE_INT);

        if ($sks === false || $sks < 1 || $sks > 24) {
            return "SKS '{$values['sks']}' tidak valid.";
        }

        if ($values['capacity'] !== null && filter_var($values['capacity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            return "kapasitas '{$values['capacity']}' tidak valid.";
        }

        if ($values['semester'] !== null && filter_var($values['semester'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 14]]) === false) {
            return "semester '{$values['semester']}' tidak valid.";
        }

        $values['start'] = $start;
        $values['end'] = $end;

        return null;
    }

    /**
     * Normalise spreadsheet time values ("07.00", "7:00", "13.3", or an Excel day fraction) to "HH:MM".
     */
    private function normalizeTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value) && (float) $value > 0 && (float) $value < 1) {
            $minutes = (int) round((float) $value * 24 * 60);
            [$hour, $minute] = [intdiv($minutes, 60), $minutes % 60];
        } elseif (is_numeric($value)) {
            [$hour, $minute] = array_map('intval', explode('.', sprintf('%.2f', (float) $value)));
        } elseif (preg_match('/^(\d{1,2})[.:](\d{2})(?:[.:]\d{2})?$/', $value, $matches) === 1) {
            [$hour, $minute] = [(int) $matches[1], (int) $matches[2]];
        } else {
            return null;
        }

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Create or update the course described by a row, matched by code first and then by name.
     *
     * @param  array<string, string|null>  $values
     */
    private function upsertCourse(array $values, ?string &$error): ?Course
    {
        $code = $values['code'] !== null ? Str::upper($values['code']) : null;
        $name = (string) $values['name'];

        $course = ($code !== null ? Course::query()->where('code', $code)->first() : null)
            ?? Course::query()->where('name', $name)->first()
            ?? new Course;

        if ($code !== null && Course::query()->where('code', $code)->whereKeyNot($course->getKey())->exists()) {
            $error = "kode {$code} sudah dipakai mata kuliah lain.";

            return null;
        }

        if (Course::query()->where('name', $name)->whereKeyNot($course->getKey())->exists()) {
            $error = "nama mata kuliah '{$name}' sudah dipakai kode lain.";

            return null;
        }

        $course->fill([
            'code' => $code ?? $course->code,
            'name' => $name,
            'semester' => $values['semester'] !== null ? (int) $values['semester'] : $course->semester,
            'type' => CourseType::fromLabel($values['type']) ?? ($course->exists ? $course->type : CourseType::Elective),
            'sks' => (int) $values['sks'],
        ])->save();

        return $course;
    }

    /**
     * Create or update the class described by a row and sync its lecturers.
     *
     * @param  array<string, string|null>  $values
     */
    private function upsertClass(AcademicPeriod $period, Course $course, array $values): CourseClass
    {
        $class = CourseClass::query()->updateOrCreate(
            [
                'academic_period_id' => $period->id,
                'course_id' => $course->id,
                'name' => $values['class'] ?? '-',
                'prodi' => $values['prodi'] ?? '',
            ],
            [
                'sks' => (int) $values['sks'],
                'day' => Day::fromLabel($values['day']),
                'start_time' => $values['start'],
                'end_time' => $values['end'],
                'room' => $values['room'],
                'capacity' => $values['capacity'] !== null ? (int) $values['capacity'] : null,
                'language' => $values['language'] ?? 'Indonesia',
                'ramadhan_schedule' => $values['ramadhan'],
            ],
        );

        $class->lecturers()->sync(Lecturer::idsFromCodes($values['lecturers']));

        return $class;
    }
}
