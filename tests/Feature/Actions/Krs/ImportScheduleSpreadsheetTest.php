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

const GRID_SCHEDULE = __DIR__.'/../../../Fixtures/jadwal-kuliah-grid-if-genap-2025-2026.xlsx';

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

it('imports every class of the department grid schedule from its visible sheet', function () {
    $result = app(ImportScheduleSpreadsheet::class)->handle(GRID_SCHEDULE, currentKrsPeriod());

    expect($result->classesCreated)->toBe(237)
        ->and($result->coursesCreated)->toBe(107)
        ->and($result->rowsSkipped)->toBe(3)
        ->and($result->errors)->toBe([
            'Sel E46: kelas Kalkulus 2 (IUP) sudah diimpor dari Sel E18; satu kelas hanya dapat memiliki satu jadwal.',
            'Sel C48: kelas Kalkulus 2 (IF, RKA) sudah diimpor dari Sel C20; satu kelas hanya dapat memiliki satu jadwal.',
            'Sel Y60: kelas Topik Dalam Data Mining C (S3) sudah diimpor dari Sel L22; satu kelas hanya dapat memiliki satu jadwal.',
        ])
        ->and(CourseClass::inPeriod(currentKrsPeriod())->count())->toBe(237);
});

it('maps grid cells onto courses and classes', function () {
    app(ImportScheduleSpreadsheet::class)->handle(GRID_SCHEDULE, currentKrsPeriod());

    $imk = Course::where('name', 'Interaksi Manusia dan Komputer')->sole();
    $imkA = $imk->classes()->where(['name' => 'A', 'prodi' => 'IF'])->sole();

    expect($imk->type)->toBe(CourseType::Mandatory)
        ->and($imk->semester)->toBe(6)
        ->and($imk->classes()->count())->toBe(9)
        ->and($imkA->day)->toBe(Day::Selasa)
        ->and([$imkA->start_time, $imkA->end_time])->toBe(['13:30', '15:20'])
        ->and($imkA->room)->toBe('IF-104')
        ->and($imkA->capacity)->toBe(50)
        ->and($imkA->language)->toBe('Indonesia')
        ->and($imkA->lecturerCodes())->toBe('DH');

    $networkSecurity = Course::where('name', 'Keamanan Jaringan')->sole();

    expect($networkSecurity->type)->toBe(CourseType::Elective)
        ->and($networkSecurity->classes()->sole()->language)->toBe('English');

    $iupClass = Course::where('name', 'Manajemen Basis Data')->sole()->classes()->where('prodi', 'IUP')->sole();

    expect($iupClass->name)->toBe('IUP I')
        ->and($iupClass->language)->toBe('English');

    $microservices = Course::where('name', 'Arsitektur Microservice dan Aplikasinya')->sole()->classes()->sole();

    expect($microservices->prodi)->toBe('RPL')
        ->and($microservices->lecturers()->pluck('code')->sort()->values()->all())->toBe(['BN', 'MA']);

    $religion = Course::where('name', 'Agama Islam')->sole();
    $religionRpl = $religion->classes()->where('prodi', 'RPL')->sole();

    expect($religion->type)->toBe(CourseType::General)
        ->and($religionRpl->day)->toBe(Day::Rabu)
        ->and([$religionRpl->start_time, $religionRpl->end_time])->toBe(['09:00', '10:50'])
        ->and($religionRpl->room)->toBeNull()
        ->and($religionRpl->label())->toBe('RPL');

    $ethics = Course::where('name', 'Etika Profesi')->sole();

    expect($ethics->semester)->toBe(8)
        ->and($ethics->sks)->toBe(2)
        ->and($ethics->classes()->sole()->prodiList())->toBe(['IF', 'RKA', 'IUP']);
});

it('detects unprefixed postgraduate grid cells from the colour legend', function () {
    app(ImportScheduleSpreadsheet::class)->handle(GRID_SCHEDULE, currentKrsPeriod());

    $auditTopic = Course::where('name', 'Topik Dalam Audit Sistem')->sole();
    $computationalIntelligence = Course::where('name', 'Kecerdasan Komputasional')->sole();
    $thesisDefense = Course::where('name', 'Sidang Proposal Tesis')->sole();

    expect($auditTopic->type)->toBe(CourseType::Elective)
        ->and($auditTopic->classes()->pluck('prodi')->unique()->all())->toBe(['S2'])
        ->and($computationalIntelligence->type)->toBe(CourseType::Mandatory)
        ->and($computationalIntelligence->classes()->pluck('prodi')->unique()->all())->toBe(['S2'])
        ->and($thesisDefense->classes()->pluck('prodi')->unique()->all())->toBe(['S2'])
        ->and(CourseClass::where('prodi', 'S2')->count())->toBe(61)
        ->and(CourseClass::where('prodi', 'S3')->count())->toBe(18)
        ->and(CourseClass::where('prodi', '')->count())->toBe(2);
});

it('imports a grid layout and reports course cells without a detail cell and detail cells without a course', function () {
    $path = scheduleCsvFile(implode("\n", [
        'Hari,Jam,IF-101 (kapasitas 40),Okupansi Kelas',
        'Senin,07.00 - 07.50,IF_Basis Data A,0.5',
        ',08.00 - 08.50,Sem 4 / AB + CD / 3 SKS,',
        ',09.00 - 09.50,IF_Grafika B,',
        ',10.00 - 10.50,,',
        ',11.00 - 11.50,Sem 2 / EF / 3 SKS,',
        "Jum'at,07.00 - 07.50,RPL_Basis Data M (EN),",
        ',08.00 - 08.50,Sem 2 / AB / 4 SKS,',
    ]));

    $result = app(ImportScheduleSpreadsheet::class)->handle($path, currentKrsPeriod());

    $classA = CourseClass::where('name', 'A')->sole();
    $classM = CourseClass::where('name', 'M')->sole();

    expect($result->classesCreated)->toBe(2)
        ->and($result->errors)->toBe([
            "Sel C4: keterangan 'Sem / dosen / SKS' di bawah 'IF_Grafika B' tidak ditemukan.",
            "Sel C6: keterangan 'Sem 2 / EF / 3 SKS' tidak didahului nama mata kuliah.",
        ])
        ->and($classA->course->name)->toBe('Basis Data')
        ->and($classA->course->type)->toBe(CourseType::Elective)
        ->and($classA->prodi)->toBe('IF')
        ->and($classA->day)->toBe(Day::Senin)
        ->and([$classA->start_time, $classA->end_time])->toBe(['07:00', '08:50'])
        ->and($classA->room)->toBe('IF-101')
        ->and($classA->capacity)->toBe(40)
        ->and($classA->lecturers()->pluck('code')->sort()->values()->all())->toBe(['AB', 'CD'])
        ->and($classM->prodi)->toBe('RPL')
        ->and($classM->day)->toBe(Day::Jumat)
        ->and($classM->language)->toBe('English')
        ->and($classM->sks)->toBe(4);
});

it('takes the course semester, type and sks most of its classes agree on', function () {
    $path = scheduleCsvFile(implode("\n", [
        'Mata Kuliah,Kelas,Prodi,Semester,Jenis,SKS,Hari,Mulai,Selesai',
        'Basis Data,A,IF,6,Wajib,3,Senin,07.00,08.50',
        'Basis Data,B,IF,6,Wajib,3,Selasa,07.00,08.50',
        'Basis Data,M,RPL,4,Pilihan,4,Rabu,07.00,08.50',
    ]));

    app(ImportScheduleSpreadsheet::class)->handle($path, currentKrsPeriod());

    $course = Course::sole();

    expect($course->semester)->toBe(6)
        ->and($course->type)->toBe(CourseType::Mandatory)
        ->and($course->sks)->toBe(3)
        ->and($course->classes()->where('name', 'M')->sole()->sks)->toBe(4);
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
