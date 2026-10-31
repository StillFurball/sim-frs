<?php

use App\Enums\CourseType;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('admin.courses'))->assertRedirect(route('login'));
});

test('students cannot open the courses page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.courses'))->assertForbidden();
});

test('admins can view the courses page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $course = Course::factory()->mandatory()->create();

    $this->get(route('admin.courses'))->assertOk()->assertSee($course->name);
});

test('admins can create a course with an uppercased code', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.courses')
        ->call('create')
        ->set('code', 'ef234699')
        ->set('name', 'Pemrograman Web Lanjut')
        ->set('semester', '6')
        ->set('type', CourseType::Mandatory->value)
        ->set('sks', '3')
        ->call('save')
        ->assertHasNoErrors();

    $course = Course::query()->sole();

    expect($course->code)->toBe('EF234699')
        ->and($course->name)->toBe('Pemrograman Web Lanjut')
        ->and($course->semester)->toBe(6)
        ->and($course->type)->toBe(CourseType::Mandatory)
        ->and($course->sks)->toBe(3);
});

test('course names and codes must be unique', function () {
    $this->actingAs(User::factory()->admin()->create());
    $existing = Course::factory()->mandatory()->create();

    Livewire::test('pages::admin.courses')
        ->call('create')
        ->set('code', $existing->code)
        ->set('name', $existing->name)
        ->call('save')
        ->assertHasErrors(['code' => 'unique', 'name' => 'unique']);

    expect(Course::query()->count())->toBe(1);
});

test('admins can update a course', function () {
    $this->actingAs(User::factory()->admin()->create());
    $course = Course::factory()->elective()->create(['sks' => 3]);

    Livewire::test('pages::admin.courses')
        ->call('edit', $course->id)
        ->assertSet('name', $course->name)
        ->set('name', 'Nama Baru')
        ->set('sks', '2')
        ->call('save')
        ->assertHasNoErrors();

    expect($course->refresh()->name)->toBe('Nama Baru')
        ->and($course->sks)->toBe(2);
});

test('changing the sks of a course only changes its classes in the active period', function () {
    $this->actingAs(User::factory()->admin()->create());
    $course = Course::factory()->create(['sks' => 3]);
    $activeClass = CourseClass::factory()->for($course)->inPeriod(AcademicPeriod::factory()->active()->create())->create();
    $pastClass = CourseClass::factory()->for($course)->inPeriod(AcademicPeriod::factory()->create(['start_year' => 2020]))->create();

    Livewire::test('pages::admin.courses')
        ->call('edit', $course->id)
        ->set('sks', '4')
        ->call('save')
        ->assertHasNoErrors();

    expect($activeClass->refresh()->sks)->toBe(4)
        ->and($pastClass->refresh()->sks)->toBe(3);
});

test('deleting a course removes its classes and enrollments', function () {
    $this->actingAs(User::factory()->admin()->create());
    $class = CourseClass::factory()->create();
    $student = User::factory()->create();
    $student->courseClasses()->attach($class);

    Livewire::test('pages::admin.courses')
        ->call('confirmDelete', $class->course_id)
        ->call('delete');

    expect(Course::query()->count())->toBe(0)
        ->and(CourseClass::query()->count())->toBe(0)
        ->and($student->courseClasses()->count())->toBe(0);
});
