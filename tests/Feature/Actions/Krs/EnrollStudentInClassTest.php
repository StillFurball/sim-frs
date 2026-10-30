<?php

use App\Actions\Krs\EnrollStudentInClass;
use App\Enums\Day;
use App\Enums\Term;
use App\Exceptions\EnrollmentException;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\User;

it('adds the class to the student KRS', function () {
    $student = User::factory()->create();
    $class = CourseClass::factory()->create();

    $replaced = app(EnrollStudentInClass::class)->handle($student, $class);

    expect($replaced)->toBeNull()
        ->and($student->courseClasses()->pluck('course_classes.id')->all())->toBe([$class->id]);
});

it('moves the student to another class of a course they already take', function () {
    $student = User::factory()->create();
    $course = Course::factory()->create();
    $classA = CourseClass::factory()->for($course)->at(Day::Senin, '07:00')->create(['name' => 'A']);
    $classB = CourseClass::factory()->for($course)->at(Day::Senin, '07:00')->create(['name' => 'B']);
    $student->courseClasses()->attach($classA);

    $replaced = app(EnrollStudentInClass::class)->handle($student, $classB);

    expect($replaced?->id)->toBe($classA->id)
        ->and($student->courseClasses()->pluck('course_classes.id')->all())->toBe([$classB->id]);
});

it('does not count the replaced class towards the SKS limit when switching', function () {
    $student = User::factory()->maxSks(18)->create();
    $course = Course::factory()->state(['sks' => 3])->create();
    $current = CourseClass::factory()->for($course)->at(Day::Senin, '07:00')->create(['name' => 'A']);
    $target = CourseClass::factory()->for($course)->at(Day::Jumat, '07:00')->create(['name' => 'B']);
    $student->courseClasses()->attach([
        $current->id,
        ...collect(range(1, 5))->map(fn (int $slot) => CourseClass::factory()
            ->for(Course::factory()->state(['sks' => 3]))
            ->at(Day::from($slot), '13:30')
            ->create()->id),
    ]);

    app(EnrollStudentInClass::class)->handle($student, $target);

    expect($student->takenSks(currentKrsPeriod()))->toBe(18)
        ->and($student->courseClasses()->whereKey($target->id)->exists())->toBeTrue();
});

it('rejects a class the student already takes', function () {
    $student = User::factory()->create();
    $class = CourseClass::factory()->create();
    $student->courseClasses()->attach($class);

    expect(fn () => app(EnrollStudentInClass::class)->handle($student, $class))
        ->toThrow(EnrollmentException::class, 'Kamu sudah mengambil');
});

it('leaves the KRS untouched when the new class clashes', function () {
    $student = User::factory()->create();
    $taken = CourseClass::factory()->at(Day::Kamis, '10:00')->create();
    $student->courseClasses()->attach($taken);
    $clashing = CourseClass::factory()->at(Day::Kamis, '11:00')->create();

    expect(fn () => app(EnrollStudentInClass::class)->handle($student, $clashing))
        ->toThrow(EnrollmentException::class, 'bentrok');

    expect($student->courseClasses()->pluck('course_classes.id')->all())->toBe([$taken->id]);
});

it('refuses a class from a period that is not active', function () {
    currentKrsPeriod();
    $student = User::factory()->create();
    $pastClass = CourseClass::factory()->inPeriod(AcademicPeriod::factory()->of(2024, Term::Ganjil)->create())->create();

    expect(fn () => app(EnrollStudentInClass::class)->handle($student, $pastClass))
        ->toThrow(EnrollmentException::class, 'sedang tidak dibuka');

    expect($student->courseClasses()->count())->toBe(0);
});

it('does not treat the same course taken in an earlier period as a class switch', function () {
    $period = currentKrsPeriod();
    $student = User::factory()->create();
    $course = Course::factory()->create();
    $earlier = CourseClass::factory()->for($course)->inPeriod(AcademicPeriod::factory()->of(2024, Term::Genap)->create())->create(['name' => 'A']);
    $student->courseClasses()->attach($earlier);
    $now = CourseClass::factory()->for($course)->inPeriod($period)->create(['name' => 'A']);

    $replaced = app(EnrollStudentInClass::class)->handle($student, $now);

    expect($replaced)->toBeNull()
        ->and($student->courseClasses()->pluck('course_classes.id')->sort()->values()->all())
        ->toBe(collect([$earlier->id, $now->id])->sort()->values()->all());
});
