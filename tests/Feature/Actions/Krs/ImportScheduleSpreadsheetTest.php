<?php

use App\Actions\Krs\ImportScheduleSpreadsheet;
use App\Enums\CourseType;
use App\Enums\Day;
use App\Enums\Term;
use App\Exceptions\ScheduleImportException;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Lecturer;
use App\Models\User;

/**
 * Write CSV contents to a temporary file and return its path.
 */
function scheduleCsvFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'krs').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

const SAMPLE_SCHEDULE = __DIR__.'/../../../Fixtures/jadwal-if-genap-2025-2026.xlsx';

it('imports every class of the sample schedule', function () {
    $result = app(ImportScheduleSpreadsheet::class)->handle(SAMPLE_SCHEDULE, currentKrsPeriod());

    expect($result->classesCreated)->toBe(44)
        ->and($result->coursesCreated)->toBe(24)
        ->and($result->errors)->toBe([])
        ->and(CourseClass::count())->toBe(44)
        ->and(Course::count())->toBe(24)
        ->and(Course::where('type', CourseType::Elective)->count())->toBe(17)
        ->and(Course::where('type', CourseType::General)->count())->toBe(4)
        ->and(Course::where('type', CourseType::Mandatory)->count())->toBe(3)
        ->and(Lecturer::count())->toBe(25);
});

it('maps the spreadsheet columns onto courses and classes', function () {
    app(ImportScheduleSpreadsheet::class)->handle(SAMPLE_SCHEDULE, currentKrsPeriod());

    $imk = Course::where('code', 'EF234602')->sole();
    $classA = $imk->classes()->where('name', 'A')->sole();

    expect($imk->name)->toBe('Interaksi Manusia dan Komputer')
        ->and($imk->type)->toBe(CourseType::Mandatory)
        ->and($imk->semester)->toBe(6)
        ->and($imk->sks)->toBe(3)
        ->and($imk->classes()->count())->toBe(7)
        ->and($classA->day)->toBe(Day::Selasa)
        ->and($classA->start_time)->toBe('13:30')
        ->and($classA->end_time)->toBe('15:20')
        ->and($classA->room)->toBe('IF-104')
        ->and($classA->capacity)->toBe(50)
        ->and($classA->prodi)->toBe('IF')
        ->and($classA->lecturerCodes())->toBe('DH');

    $ethics = Course::where('name', 'Etika Profesi')->sole();
    $ethicsClass = $ethics->classes()->sole();

    expect($ethics->semester)->toBe(8)
        ->and($ethics->sks)->toBe(2)
        ->and($ethicsClass->day)->toBe(Day::Jumat)
        ->and($ethicsClass->capacity)->toBeNull()
        ->and($ethicsClass->prodiList())->toBe(['IF', 'RKA', 'IUP'])
        ->and($ethicsClass->lecturers()->pluck('code')->sort()->values()->all())->toBe(['AL', 'NF', 'RN']);

    $religion = Course::where('code', 'UG234901')->sole();

    expect($religion->type)->toBe(CourseType::General)
        ->and($religion->classes()->pluck('prodi')->sort()->values()->all())->toBe(['IF, RKA', 'IUP', 'RPL'])
        ->and($religion->classes()->where('prodi', 'IUP')->sole()->label())->toBe('IUP');

    $deepLearningEnglish = Course::where('name', 'Deep Learning')->sole()->classes()->where('name', 'U')->sole();

    expect($deepLearningEnglish->language)->toBe('English')
        ->and($deepLearningEnglish->ramadhan_schedule)->toBe('Rabu 10.00-11.50');
});

it('updates existing classes instead of duplicating them on re-import', function () {
    $import = app(ImportScheduleSpreadsheet::class);
    $import->handle(SAMPLE_SCHEDULE, currentKrsPeriod());

    $class = CourseClass::whereRelation('course', 'code', 'EF234602')->where('name', 'A')->sole();
    $class->update(['room' => 'Ruang Lama']);
    $student = User::factory()->create();
    $student->courseClasses()->attach($class);

    $result = $import->handle(SAMPLE_SCHEDULE, currentKrsPeriod());

    expect($result->classesCreated)->toBe(0)
        ->and($result->classesUpdated)->toBe(44)
        ->and($result->coursesCreated)->toBe(0)
        ->and(CourseClass::count())->toBe(44)
        ->and($class->refresh()->room)->toBe('IF-104')
        ->and($student->courseClasses()->whereKey($class->id)->exists())->toBeTrue();
});

it('removes only the target period classes and their enrollments when replacing existing data', function () {
    $period = currentKrsPeriod();
    $otherPeriod = AcademicPeriod::factory()->of(2024, Term::Ganjil)->create();
    $oldClass = CourseClass::factory()->inPeriod($period)->create();
    $otherPeriodClass = CourseClass::factory()->inPeriod($otherPeriod)->create();
    $student = User::factory()->create();
    $student->courseClasses()->attach([$oldClass->id, $otherPeriodClass->id]);

    app(ImportScheduleSpreadsheet::class)->handle(SAMPLE_SCHEDULE, $period, replaceExisting: true);

    expect(CourseClass::find($oldClass->id))->toBeNull()
        ->and(Course::find($oldClass->course_id))->not->toBeNull()
        ->and(CourseClass::find($otherPeriodClass->id))->not->toBeNull()
        ->and($student->courseClasses()->pluck('course_classes.id')->all())->toBe([$otherPeriodClass->id])
        ->and(CourseClass::inPeriod($period)->count())->toBe(44);
});

it('imports the same schedule into two periods as separate classes', function () {
    $import = app(ImportScheduleSpreadsheet::class);
    $genap = currentKrsPeriod();
    $ganjil = AcademicPeriod::factory()->of(2026, Term::Ganjil)->create();

    $import->handle(SAMPLE_SCHEDULE, $genap);
    $result = $import->handle(SAMPLE_SCHEDULE, $ganjil);

    expect($result->classesCreated)->toBe(44)
        ->and($result->coursesCreated)->toBe(0)
        ->and(CourseClass::inPeriod($genap)->count())->toBe(44)
        ->and(CourseClass::inPeriod($ganjil)->count())->toBe(44)
        ->and(Course::count())->toBe(24);
});

it('imports a CSV with title rows, semicolons and a combined time column', function () {
    $path = scheduleCsvFile(implode("\n", [
        'Jadwal Kuliah Genap',
        '',
        'Kode;Nama MK;Kelas;Jenis;SKS;Dosen;Hari;Jam;Ruangan;Kuota',
        'EF1;Basis Data;A;Wajib;4;AB / CD;jumat;07.00 - 09.50;IF-101;30',
        ';Grafika;B;Pilihan;3;;Monday;13:30-15:20;;',
    ]));

    $result = app(ImportScheduleSpreadsheet::class)->handle($path, currentKrsPeriod());

    $database = CourseClass::whereRelation('course', 'code', 'EF1')->sole();
    $graphics = CourseClass::whereRelation('course', 'name', 'Grafika')->sole();

    expect($result->classesCreated)->toBe(2)
        ->and($database->course->type)->toBe(CourseType::Mandatory)
        ->and($database->course->sks)->toBe(4)
        ->and($database->day)->toBe(Day::Jumat)
        ->and([$database->start_time, $database->end_time])->toBe(['07:00', '09:50'])
        ->and($database->capacity)->toBe(30)
        ->and($database->lecturers()->pluck('code')->sort()->values()->all())->toBe(['AB', 'CD'])
        ->and($graphics->course->type)->toBe(CourseType::Elective)
        ->and($graphics->day)->toBe(Day::Senin)
        ->and($graphics->capacity)->toBeNull()
        ->and($graphics->language)->toBe('Indonesia');
});

it('keeps the sks of past periods when a new period changes the sks of a course', function () {
    $past = AcademicPeriod::factory()->create(['start_year' => 2020]);
    $student = User::factory()->create();
    $pastClass = CourseClass::factory()->inPeriod($past)
        ->for(Course::factory()->state(['code' => 'EF1', 'sks' => 3]))
        ->create(['name' => 'A', 'prodi' => '']);
    $student->courseClasses()->attach($pastClass);

    app(ImportScheduleSpreadsheet::class)->handle(scheduleCsvFile(implode("\n", [
        'Kode;Mata Kuliah;Kelas;SKS;Hari;Mulai;Selesai',
        'EF1;Basis Data;A;4;Senin;07.00;09.50',
    ])), currentKrsPeriod());

    expect(CourseClass::inPeriod(currentKrsPeriod())->sole()->sks)->toBe(4)
        ->and($pastClass->refresh()->sks)->toBe(3)
        ->and($student->takenSks($past))->toBe(3);
});

it('normalises spreadsheet time formats', function (string $start, string $expected) {
    $path = scheduleCsvFile("Mata Kuliah,Hari,SKS,Mulai,Selesai\nAlgoritma,Senin,3,{$start},23:00");

    app(ImportScheduleSpreadsheet::class)->handle($path, currentKrsPeriod());

    expect(CourseClass::sole()->start_time)->toBe($expected);
})->with([
    'dotted' => ['07.00', '07:00'],
    'single digit hour' => ['7:00', '07:00'],
    'with seconds' => ['13:30:00', '13:30'],
    'excel day fraction' => ['0.5625', '13:30'],
    'decimal hour' => ['13.3', '13:30'],
]);

it('reports invalid rows while importing the valid ones', function () {
    $path = scheduleCsvFile(implode("\n", [
        'Mata Kuliah,Kelas,Hari,SKS,Mulai,Selesai',
        'Algoritma,A,Senin,3,07.00,08.50',
        'Struktur Data,A,Minggu Depan,3,07.00,08.50',
        'Jaringan,A,Selasa,3,10.00,09.00',
        'Kalkulus,A,Rabu,tiga,07.00,08.50',
        'Ringkasan,,,,,',
    ]));

    $result = app(ImportScheduleSpreadsheet::class)->handle($path, currentKrsPeriod());

    expect($result->classesCreated)->toBe(1)
        ->and($result->rowsSkipped)->toBe(3)
        ->and($result->errors)->toBe([
            "Baris 3: hari 'Minggu Depan' tidak dikenali.",
            'Baris 4: jam selesai (09:00) harus setelah jam mulai (10:00).',
            "Baris 5: SKS 'tiga' tidak valid.",
        ]);
});

it('rejects a spreadsheet without a header row', function () {
    $path = scheduleCsvFile("foo,bar\n1,2");

    expect(fn () => app(ImportScheduleSpreadsheet::class)->handle($path, currentKrsPeriod()))
        ->toThrow(ScheduleImportException::class, 'Baris header tidak ditemukan.');
});

it('rejects a header without the required columns', function () {
    $path = scheduleCsvFile("Mata Kuliah,Hari,Mulai,Selesai\nAlgoritma,Senin,07.00,08.50");

    expect(fn () => app(ImportScheduleSpreadsheet::class)->handle($path, currentKrsPeriod()))
        ->toThrow(ScheduleImportException::class, 'Kolom wajib tidak ditemukan: sks.');
});

it('rolls back and explains when no row is valid', function () {
    $path = scheduleCsvFile("Mata Kuliah,Hari,SKS,Mulai,Selesai\nAlgoritma,Libur,3,07.00,08.50");

    expect(fn () => app(ImportScheduleSpreadsheet::class)->handle($path, currentKrsPeriod(), replaceExisting: true))
        ->toThrow(ScheduleImportException::class, "Tidak ada baris jadwal yang valid. Baris 2: hari 'Libur' tidak dikenali.");
});

it('keeps existing data when a replacing import fails', function () {
    $class = CourseClass::factory()->create();
    $path = scheduleCsvFile("Mata Kuliah,Hari,SKS,Mulai,Selesai\nAlgoritma,Libur,3,07.00,08.50");

    try {
        app(ImportScheduleSpreadsheet::class)->handle($path, currentKrsPeriod(), replaceExisting: true);
    } catch (ScheduleImportException) {
        //
    }

    expect(CourseClass::find($class->id))->not->toBeNull();
});

it('rejects a file that is not an xlsx workbook', function () {
    $path = tempnam(sys_get_temp_dir(), 'krs').'.xlsx';
    file_put_contents($path, 'not a zip archive');

    expect(fn () => app(ImportScheduleSpreadsheet::class)->handle($path, currentKrsPeriod()))
        ->toThrow(ScheduleImportException::class, 'File bukan workbook .xlsx yang valid.');
});
