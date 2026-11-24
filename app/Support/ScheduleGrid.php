<?php

namespace App\Support;

use App\Enums\Day;
use Illuminate\Support\Str;

/**
 * Turns a grid-style schedule into flat schedule records: days and time slots run down the side, rooms run across the
 * header row, and each class is a course cell (e.g. "IF_Basis Data A (EN)") above a "Sem 4 / AB, CD / 3 SKS" cell.
 *
 * This is the layout of the department's "Jadwal Kuliah" sheet. Bold course cells are mandatory courses, italic ones
 * are taught in English, and "SKPB" columns hold general courses whose room is decided by the institute.
 *
 * Postgraduate cells carry no "PRODI_" prefix; their program is only told by the cell colour, explained by the legend
 * below the grid ("S2 Teknik Informatika" followed by a colour swatch per "MK Wajib" / "MK Pilihan" / ... row).
 */
class ScheduleGrid
{
    /**
     * Number of leading rows searched for the header row.
     */
    private const int HEADER_SEARCH_ROWS = 30;

    /**
     * A time slot such as "07.00 - 07.50".
     */
    private const string SLOT_PATTERN = '/^(\d{1,2}[.:]\d{2})\s*[-–—]\s*(\d{1,2}[.:]\d{2})$/u';

    private const string SEMESTER_PATTERN = '/^sem(?:ester)?\.?\s*(\d+)$/i';

    private const string SKS_PATTERN = '/^(\d+)\s*sks$/i';

    /**
     * Extract the classes of a grid-style worksheet, or return null when the worksheet is not laid out as a grid.
     *
     * @return list<array{label: string, values: array<string, string|null>, error: string|null}>|null
     */
    public function records(Worksheet $sheet): ?array
    {
        $header = $this->locateHeader($sheet);

        if ($header === null) {
            return null;
        }

        [$headerIndex, $dayColumn, $timeColumn] = $header;
        $places = $this->places($sheet->rows[$headerIndex], $dayColumn, $timeColumn);
        $slots = $this->slots($sheet, $headerIndex, $dayColumn, $timeColumn);
        $legend = $this->legend($sheet, $headerIndex, $dayColumn, $timeColumn);
        $records = [];
        $consumed = [];

        foreach ($slots as $position => $slot) {
            foreach ($places as $column => $place) {
                $text = $this->text($sheet->value($slot['row'], $column));

                if ($text === null || isset($consumed[$slot['row']][$column])) {
                    continue;
                }

                $label = 'Sel '.$this->cellReference($slot['row'], $column);
                $segments = $this->segments($text);

                if ($this->isDetail($segments)) {
                    $records[] = $this->failed($label, "keterangan '{$text}' tidak didahului nama mata kuliah.");

                    continue;
                }

                if ($this->hasSks($segments)) {
                    $title = (string) array_shift($segments);
                    $records[] = $this->record($sheet, $legend, $label, $column, $place, $slot, $slot, $title, $segments);

                    continue;
                }

                $next = $slots[$position + 1] ?? null;
                $detail = $next !== null && $next['day'] === $slot['day']
                    ? $this->segments((string) $this->text($sheet->value($next['row'], $column)))
                    : [];

                if ($next === null || ! $this->isDetail($detail)) {
                    $records[] = $this->failed($label, "keterangan 'Sem / dosen / SKS' di bawah '{$text}' tidak ditemukan.");

                    continue;
                }

                $consumed[$next['row']][$column] = true;
                $records[] = $this->record($sheet, $legend, $label, $column, $place, $slot, $next, $text, $detail);
            }
        }

        return $records;
    }

    /**
     * Find the header row holding both a "Hari" and a "Jam" column.
     *
     * @return array{0: int, 1: int, 2: int}|null Header row index, day column and time column.
     */
    private function locateHeader(Worksheet $sheet): ?array
    {
        foreach (array_slice($sheet->rows, 0, self::HEADER_SEARCH_ROWS) as $index => $row) {
            $dayColumn = null;
            $timeColumn = null;

            foreach ($row as $column => $value) {
                $key = preg_replace('/[^a-z]/', '', Str::lower((string) $value));

                if ($key === 'hari') {
                    $dayColumn ??= $column;
                } elseif (in_array($key, ['jam', 'waktu'], true)) {
                    $timeColumn ??= $column;
                }
            }

            if ($dayColumn !== null && $timeColumn !== null) {
                return [$index, $dayColumn, $timeColumn];
            }
        }

        return null;
    }

    /**
     * Describe the room (or general course group) of every schedule column. A blank header belongs to a merged
     * header cell, so it takes the description of the column to its left.
     *
     * @param  list<string|null>  $header
     * @return array<int, array{general: bool, prodi: string|null, room: string|null, capacity: string|null}>
     */
    private function places(array $header, int $dayColumn, int $timeColumn): array
    {
        $places = [];
        $previous = null;

        foreach ($header as $column => $value) {
            $value = $this->text($value);

            if ($column === $dayColumn || $column === $timeColumn || ($value !== null && Str::contains($value, 'okupansi', ignoreCase: true))) {
                $previous = null;

                continue;
            }

            if ($value !== null) {
                $previous = $this->place($value);
            }

            if ($previous !== null) {
                $places[$column] = $previous;
            }
        }

        return $places;
    }

    /**
     * Describe a column from its header, e.g. "IF-101 (kapasitas 50)" or "SKPB IUP".
     *
     * @return array{general: bool, prodi: string|null, room: string|null, capacity: string|null}
     */
    private function place(string $header): array
    {
        if (Str::contains($header, 'skpb', ignoreCase: true)) {
            return [
                'general' => true,
                'prodi' => Str::contains($header, 'iup', ignoreCase: true) ? 'IUP' : null,
                'room' => null,
                'capacity' => null,
            ];
        }

        $capacity = preg_match('/kapasitas\D*(\d+)/i', $header, $matches) === 1 ? $matches[1] : null;

        return [
            'general' => false,
            'prodi' => null,
            'room' => $this->collapse((string) preg_replace('/\(\s*kapasitas[^)]*\)/i', '', $header)),
            'capacity' => $capacity,
        ];
    }

    /**
     * List the time slot rows below the header, carrying each day label down over its (merged) block.
     *
     * @return list<array{row: int, day: string, start: string, end: string}>
     */
    private function slots(Worksheet $sheet, int $headerIndex, int $dayColumn, int $timeColumn): array
    {
        $slots = [];
        $day = null;

        for ($row = $headerIndex + 1, $total = count($sheet->rows); $row < $total; $row++) {
            $dayLabel = $this->text($sheet->value($row, $dayColumn));

            if ($dayLabel !== null) {
                $day = Day::fromLabel($dayLabel) !== null ? $dayLabel : null;
            }

            if ($day !== null && preg_match(self::SLOT_PATTERN, (string) $this->text($sheet->value($row, $timeColumn)), $matches) === 1) {
                $slots[] = ['row' => $row, 'day' => $day, 'start' => $matches[1], 'end' => $matches[2]];
            }
        }

        return $slots;
    }

    /**
     * Read the colour legend below the grid: under a postgraduate heading such as "S2 Teknik Informatika" (in the day
     * column), each row pairs a colour swatch (day column) with a course kind such as "MK Pilihan" (time column).
     *
     * @return array<string, array{prodi: string, type: string|null}> Program and course type, keyed by ARGB fill colour.
     */
    private function legend(Worksheet $sheet, int $headerIndex, int $swatchColumn, int $labelColumn): array
    {
        $legend = [];
        $program = null;

        for ($row = $headerIndex + 1, $total = count($sheet->rows); $row < $total; $row++) {
            $heading = $this->text($sheet->value($row, $swatchColumn));

            if ($heading !== null) {
                $program = preg_match('/^(S[23])\b/i', $heading, $matches) === 1 ? Str::upper($matches[1]) : null;

                continue;
            }

            $fill = $sheet->fill($row, $swatchColumn);
            $kind = Str::lower((string) $this->text($sheet->value($row, $labelColumn)));

            if ($program !== null && $fill !== null && $kind !== '') {
                $legend[$fill] ??= [
                    'prodi' => $program,
                    'type' => match (true) {
                        str_contains($kind, 'wajib') => 'Wajib',
                        str_contains($kind, 'pilihan') => 'Pilihan',
                        default => null,
                    },
                ];
            }
        }

        return $legend;
    }

    /**
     * Build the schedule record of a class that starts in one slot and ends in another.
     *
     * @param  array<string, array{prodi: string, type: string|null}>  $legend
     * @param  array{general: bool, prodi: string|null, room: string|null, capacity: string|null}  $place
     * @param  array{row: int, day: string, start: string, end: string}  $first
     * @param  array{row: int, day: string, start: string, end: string}  $last
     * @param  list<string>  $detail
     * @return array{label: string, values: array<string, string|null>, error: string|null}
     */
    private function record(Worksheet $sheet, array $legend, string $label, int $column, array $place, array $first, array $last, string $title, array $detail): array
    {
        $course = $place['general'] ? $this->generalCourse($title, $place['prodi']) : $this->course($title);

        if ($course['name'] === '') {
            return $this->failed($label, "nama mata kuliah pada '{$title}' tidak dikenali.");
        }

        $legendEntry = $place['general'] ? null : ($legend[(string) $sheet->fill($first['row'], $column)] ?? null);

        if ($course['prodi'] === '' && $legendEntry !== null) {
            $course['prodi'] = $legendEntry['prodi'];
        }

        $semester = null;
        $sks = null;
        $lecturers = [];

        foreach ($detail as $segment) {
            if (preg_match(self::SEMESTER_PATTERN, $segment, $matches) === 1) {
                $semester = $matches[1];
            } elseif (preg_match(self::SKS_PATTERN, $segment, $matches) === 1) {
                $sks = $matches[1];
            } else {
                $lecturers[] = (string) preg_replace('/\s*\+\s*/', ', ', $segment);
            }
        }

        $isEnglish = $course['english'] || $course['prodi'] === 'IUP' || $sheet->isItalic($first['row'], $column);

        return [
            'label' => $label,
            'error' => null,
            'values' => [
                'semester' => $semester,
                'code' => null,
                'name' => $course['name'],
                'class' => $course['class'],
                'prodi' => $course['prodi'],
                'type' => match (true) {
                    $place['general'] => 'SKPB',
                    $legendEntry !== null && $legendEntry['type'] !== null => $legendEntry['type'],
                    $sheet->isBold($first['row'], $column) => 'Wajib',
                    default => 'Pilihan',
                },
                'sks' => $sks,
                'lecturers' => $lecturers === [] ? null : implode(', ', $lecturers),
                'day' => $first['day'],
                'start' => $first['start'],
                'end' => $last['end'],
                'time' => null,
                'room' => $place['room'],
                'capacity' => $place['capacity'],
                'language' => $isEnglish ? 'English' : 'Indonesia',
                'ramadhan' => null,
            ],
        ];
    }

    /**
     * Split a course cell such as "IUP_Basis Data IUP I", "IF, RKA, & IUP_Etika Profesi A" or "Tesis 1 A - Riset" into
     * course name, class name and study programs.
     *
     * @return array{name: string, class: string, prodi: string, english: bool}
     */
    private function course(string $title): array
    {
        $isEnglish = preg_match('/\(\s*EN\s*\)/i', $title) === 1;
        $title = $this->collapse((string) preg_replace('/\([^)]*\)/', '', $title));
        $prodi = '';
        $class = '-';

        if (preg_match('/^([^_]+)_(.*)$/', $title, $matches) === 1) {
            $prodi = $this->prodi($matches[1]);
            $title = trim($matches[2]);
        }

        if (preg_match('/^(.+?)\s+(IUP\s+)?([A-Z])(?:\s*-\s*(\p{L}+))?$/u', $title, $matches) === 1) {
            $title = $matches[1];
            $class = $matches[3].(($matches[4] ?? '') !== '' ? ' - '.$matches[4] : '');

            if ($matches[2] !== '' || $prodi === 'IUP') {
                $class = 'IUP '.$class;
            }
        }

        return ['name' => $title, 'class' => $class, 'prodi' => $prodi, 'english' => $isEnglish];
    }

    /**
     * Split a general course cell such as "KALKULUS 2 - IF & RKA" into course name and study programs.
     *
     * @return array{name: string, class: string, prodi: string, english: bool}
     */
    private function generalCourse(string $title, ?string $columnProdi): array
    {
        $name = $title;
        $prodi = $columnProdi ?? '';

        if (preg_match('/^(.+?)\s+[-–]\s+(.+)$/u', $title, $matches) === 1) {
            $name = $matches[1];
            $prodi = $this->prodi($matches[2]);
        }

        if ($name === Str::upper($name)) {
            $name = Str::title(Str::lower($name));
        }

        return ['name' => $name, 'class' => '-', 'prodi' => $prodi, 'english' => false];
    }

    /**
     * Normalise a study program list such as "IF, RKA, & IUP" or "RPL & RKA" to "IF, RKA, IUP".
     */
    private function prodi(string $value): string
    {
        $programs = preg_split('/\s*(?:,|&|\bdan\b)\s*/i', $value) ?: [];

        return implode(', ', array_filter(array_map(fn (string $program): string => Str::upper(trim($program)), $programs)));
    }

    /**
     * Split a cell on "/" into its trimmed, non-empty segments.
     *
     * @return list<string>
     */
    private function segments(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode('/', $text)), fn (string $segment): bool => $segment !== ''));
    }

    /**
     * Determine whether the segments are a class detail cell such as "Sem 4 / AB / 3 SKS" or "3 SKS / SEM 2".
     *
     * @param  list<string>  $segments
     */
    private function isDetail(array $segments): bool
    {
        return $segments !== []
            && (preg_match(self::SEMESTER_PATTERN, $segments[0]) === 1 || preg_match(self::SKS_PATTERN, $segments[0]) === 1)
            && $this->hasSks($segments);
    }

    /**
     * Determine whether any segment states the number of credits.
     *
     * @param  list<string>  $segments
     */
    private function hasSks(array $segments): bool
    {
        foreach ($segments as $segment) {
            if (preg_match(self::SKS_PATTERN, $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a record for a cell that cannot be imported.
     *
     * @return array{label: string, values: array<string, string|null>, error: string|null}
     */
    private function failed(string $label, string $error): array
    {
        return ['label' => $label, 'values' => [], 'error' => $error];
    }

    /**
     * Get the collapsed text of a cell, or null when it holds no letters or digits.
     */
    private function text(?string $value): ?string
    {
        if ($value === null || preg_match('/[\p{L}\p{N}]/u', $value) !== 1) {
            return null;
        }

        return $this->collapse($value);
    }

    /**
     * Collapse runs of whitespace (including line breaks and non-breaking spaces) into single spaces.
     */
    private function collapse(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }

    /**
     * Convert zero-based coordinates into a cell reference such as "G5".
     */
    private function cellReference(int $row, int $column): string
    {
        $letters = '';

        for ($number = $column + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $letters = chr(65 + ($number - 1) % 26).$letters;
        }

        return $letters.($row + 1);
    }
}
