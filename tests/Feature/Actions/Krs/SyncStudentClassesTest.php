<?php

use App\Actions\Krs\SyncStudentClasses;
use App\Enums\Day;
use App\Enums\Term;
use App\Exceptions\EnrollmentException;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\User;

it('replaces the student KRS with a valid selection', function () {
    $student = User::factory()->create();
    $previous = CourseClass::factory()->at(Day::Senin, '07:00')->create();
    $student->courseClasses()->attach($previous);

    $first = CourseClass::factory()->at(Day::Selasa, '07:00')->create();
    $second = CourseClass::factory()->at(Day::Selasa, '10:00')->create();

    app(SyncStudentClasses::class)->handle($student, currentKrsPeriod(), [$first->id, $second->id]);

    expect($student->courseClasses()->pluck('course_classes.id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

it('rejects a selection that exceeds the SKS limit', function () {
    $student = User::factory()->maxSks(18)->create();
    $classes = collect(range(0, 6))->map(fn (int $slot) => CourseClass::factory()
        ->for(Course::factory()->state(['sks' => 3]))
        ->at(Day::from(intdiv($slot, 2) + 1), $slot % 2 === 0 ? '07:00' : '10:00')
        ->create());

    expect(fn () => app(SyncStudentClasses::class)->handle($student, currentKrsPeriod(), $classes->pluck('id')->all()))
        ->toThrow(EnrollmentException::class, 'Total SKS (21) melebihi batas SKS kamu (18).');

    expect($student->courseClasses()->count())->toBe(0);
});

it('accepts a selection exactly at the SKS limit', function () {
    $student = User::factory()->maxSks(18)->create();
    $classes = collect(range(0, 5))->map(fn (int $slot) => CourseClass::factory()
        ->for(Course::factory()->state(['sks' => 3]))
        ->at(Day::from(intdiv($slot, 2) + 1), $slot % 2 === 0 ? '07:00' : '10:00')
        ->create());

    app(SyncStudentClasses::class)->handle($student, currentKrsPeriod(), $classes->pluck('id')->all());

    expect($student->takenSks(currentKrsPeriod()))->toBe(18);
});

it('rejects overlapping schedules', function (string $otherStart, string $otherEnd) {
    $student = User::factory()->create();
    $class = CourseClass::factory()->at(Day::Rabu, '10:00', '11:50')->create();
    $other = CourseClass::factory()->at(Day::Rabu, $otherStart, $otherEnd)->create();

    expect(fn () => app(SyncStudentClasses::class)->handle($student, currentKrsPeriod(), [$class->id, $other->id]))
        ->toThrow(EnrollmentException::class, 'bentrok');
})->with([
    'same slot' => ['10:00', '11:50'],
    'starts during the class' => ['11:00', '12:50'],
    'ends during the class' => ['09:00', '10:50'],
    'contains the class' => ['09:00', '12:00'],
]);

it('allows back-to-back classes on the same day', function () {
    $student = User::factory()->create();
    $morning = CourseClass::factory()->at(Day::Rabu, '07:00', '08:50')->create();
    $next = CourseClass::factory()->at(Day::Rabu, '08:50', '10:40')->create();

    app(SyncStudentClasses::class)->handle($student, currentKrsPeriod(), [$morning->id, $next->id]);

    expect($student->courseClasses()->count())->toBe(2);
});

it('rejects two classes of the same course', function () {
    $student = User::factory()->create();
    $course = Course::factory()->create(['name' => 'Interaksi Manusia dan Komputer']);
    $classA = CourseClass::factory()->for($course)->at(Day::Senin, '07:00')->create(['name' => 'A']);
    $classB = CourseClass::factory()->for($course)->at(Day::Selasa, '07:00')->create(['name' => 'B']);

    expect(fn () => app(SyncStudentClasses::class)->handle($student, currentKrsPeriod(), [$classA->id, $classB->id]))
        ->toThrow(EnrollmentException::class, 'Mata kuliah Interaksi Manusia dan Komputer hanya boleh diambil di satu kelas.');
});

it('rejects a full class the student has not taken yet', function () {
    $student = User::factory()->create();
    $class = CourseClass::factory()->capacity(1)->create();
    $class->students()->attach(User::factory()->create());

    expect(fn () => app(SyncStudentClasses::class)->handle($student, currentKrsPeriod(), [$class->id]))
        ->toThrow(EnrollmentException::class, 'sudah penuh');
});

it('keeps a full class the student already holds', function () {
    $student = User::factory()->create();
    $class = CourseClass::factory()->capacity(1)->at(Day::Senin, '07:00')->create();
    $class->students()->attach($student);
    $other = CourseClass::factory()->at(Day::Sabtu, '07:00')->create();

    app(SyncStudentClasses::class)->handle($student, currentKrsPeriod(), [$class->id, $other->id]);

    expect($student->courseClasses()->count())->toBe(2);
});

it('treats a class without capacity as unlimited', function () {
    $student = User::factory()->create();
    $class = CourseClass::factory()->capacity(null)->create();
    $class->students()->attach(User::factory()->count(3)->create());

    app(SyncStudentClasses::class)->handle($student, currentKrsPeriod(), [$class->id]);

    expect($student->courseClasses()->count())->toBe(1);
});

it('forbids administrators from filling a KRS', function () {
    $admin = User::factory()->admin()->create();
    $class = CourseClass::factory()->create();

    expect(fn () => app(SyncStudentClasses::class)->handle($admin, currentKrsPeriod(), [$class->id]))
        ->toThrow(EnrollmentException::class, 'Hanya mahasiswa yang dapat mengisi KRS.');
});

it('rejects class ids that do not exist', function () {
    $student = User::factory()->create();

    expect(fn () => app(SyncStudentClasses::class)->handle($student, currentKrsPeriod(), [999]))
        ->toThrow(EnrollmentException::class, 'Sebagian kelas yang dipilih sudah tidak tersedia.');
});

it('refuses to change the KRS of a period that is not active', function () {
    currentKrsPeriod();
    $pastPeriod = AcademicPeriod::factory()->of(2024, Term::Genap)->create();
    $student = User::factory()->create();
    $class = CourseClass::factory()->inPeriod($pastPeriod)->create();

    expect(fn () => app(SyncStudentClasses::class)->handle($student, $pastPeriod, [$class->id]))
        ->toThrow(EnrollmentException::class, 'Pengisian KRS periode 2024/2025 Genap sedang tidak dibuka.');

    expect($student->courseClasses()->count())->toBe(0);
});

it('rejects a class scheduled in another period', function () {
    $period = currentKrsPeriod();
    $otherPeriod = AcademicPeriod::factory()->of(2024, Term::Ganjil)->create();
    $student = User::factory()->create();
    $class = CourseClass::factory()->inPeriod($otherPeriod)->create();

    expect(fn () => app(SyncStudentClasses::class)->handle($student, $period, [$class->id]))
        ->toThrow(EnrollmentException::class, 'tidak dijadwalkan pada periode');
});

it('keeps the enrollments of other periods when replacing the KRS', function () {
    $period = currentKrsPeriod();
    $pastPeriod = AcademicPeriod::factory()->of(2024, Term::Genap)->create();
    $student = User::factory()->create();
    $pastClass = CourseClass::factory()->inPeriod($pastPeriod)->at(Day::Senin, '07:00')->create();
    $previous = CourseClass::factory()->inPeriod($period)->at(Day::Selasa, '07:00')->create();
    $student->courseClasses()->attach([$pastClass->id, $previous->id]);
    $next = CourseClass::factory()->inPeriod($period)->at(Day::Senin, '07:00')->create();

    app(SyncStudentClasses::class)->handle($student, $period, [$next->id]);

    expect($student->classesInPeriod($period)->pluck('course_classes.id')->all())->toBe([$next->id])
        ->and($student->classesInPeriod($pastPeriod)->pluck('course_classes.id')->all())->toBe([$pastClass->id]);
});

it('counts the SKS limit per period', function () {
    $period = currentKrsPeriod();
    $pastPeriod = AcademicPeriod::factory()->of(2024, Term::Genap)->create();
    $student = User::factory()->maxSks(18)->create();
    $student->courseClasses()->attach(collect(range(0, 5))->map(fn (int $slot) => CourseClass::factory()
        ->inPeriod($pastPeriod)
        ->for(Course::factory()->state(['sks' => 3]))
        ->at(Day::from(intdiv($slot, 2) + 1), $slot % 2 === 0 ? '07:00' : '10:00')
        ->create()->id));
    $class = CourseClass::factory()->inPeriod($period)->create();

    app(SyncStudentClasses::class)->handle($student, $period, [$class->id]);

    expect($student->takenSks($pastPeriod))->toBe(18)
        ->and($student->takenSks($period))->toBe(3);
});
