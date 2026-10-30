<?php

use App\Enums\Day;
use App\Enums\Term;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Lecturer;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('admin.classes'))->assertRedirect(route('login'));
});

test('students cannot open the classes page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.classes'))->assertForbidden();
});

test('admins can view the classes page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $class = CourseClass::factory()->create();

    $this->get(route('admin.classes'))->assertOk()->assertSee($class->course->name);
});

test('admins can create a class with lecturers from comma separated codes', function () {
    $this->actingAs(User::factory()->admin()->create());
    $period = AcademicPeriod::factory()->active()->create();
    $course = Course::factory()->mandatory()->create();
    $existingLecturer = Lecturer::factory()->create(['code' => 'BN']);

    Livewire::test('pages::admin.classes')
        ->call('create')
        ->set('courseId', (string) $course->id)
        ->set('name', 'A')
        ->set('prodi', '')
        ->set('day', (string) Day::Selasa->value)
        ->set('startTime', '10:00')
        ->set('endTime', '11:50')
        ->set('room', 'IF-101')
        ->set('capacity', '40')
        ->set('lecturerCodes', 'BN, MA / BN')
        ->call('save')
        ->assertHasNoErrors();

    $class = CourseClass::query()->with('lecturers')->sole();

    expect($class->course_id)->toBe($course->id)
        ->and($class->academic_period_id)->toBe($period->id)
        ->and($class->prodi)->toBe('')
        ->and($class->day)->toBe(Day::Selasa)
        ->and($class->start_time)->toBe('10:00')
        ->and($class->end_time)->toBe('11:50')
        ->and($class->capacity)->toBe(40)
        ->and($class->lecturers->pluck('code')->sort()->values()->all())->toBe(['BN', 'MA'])
        ->and(Lecturer::query()->count())->toBe(2)
        ->and($class->lecturers->contains($existingLecturer))->toBeTrue();
});

test('the class end time must be after the start time', function () {
    $this->actingAs(User::factory()->admin()->create());
    AcademicPeriod::factory()->active()->create();
    $course = Course::factory()->create();

    Livewire::test('pages::admin.classes')
        ->call('create')
        ->set('courseId', (string) $course->id)
        ->set('name', 'A')
        ->set('startTime', '10:00')
        ->set('endTime', '09:00')
        ->call('save')
        ->assertHasErrors(['endTime' => 'after']);

    expect(CourseClass::query()->count())->toBe(0);
});

test('the period filter lists only the classes of the selected period', function () {
    $this->actingAs(User::factory()->admin()->create());
    $active = AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();
    $previous = AcademicPeriod::factory()->of(2025, Term::Ganjil)->create();
    $activeClass = CourseClass::factory()->inPeriod($active)->create();
    $previousClass = CourseClass::factory()->inPeriod($previous)->create();

    $component = Livewire::test('pages::admin.classes')->assertSet('periodFilter', (string) $active->id);

    expect($component->get('classes')->pluck('id')->all())->toBe([$activeClass->id]);

    $component->set('periodFilter', (string) $previous->id);

    expect($component->get('classes')->pluck('id')->all())->toBe([$previousClass->id]);
});

test('the same class can be scheduled again in another period', function () {
    $this->actingAs(User::factory()->admin()->create());
    $existing = CourseClass::factory()->create(['name' => 'A', 'prodi' => 'IF']);
    $nextPeriod = AcademicPeriod::factory()->of(2026, Term::Ganjil)->create();

    Livewire::test('pages::admin.classes')
        ->call('create')
        ->set('periodId', (string) $nextPeriod->id)
        ->set('courseId', (string) $existing->course_id)
        ->set('name', 'A')
        ->set('prodi', 'IF')
        ->call('save')
        ->assertHasNoErrors();

    expect(CourseClass::query()->inPeriod($nextPeriod)->where('course_id', $existing->course_id)->count())->toBe(1);
});

test('adding a class is disabled until an academic period exists', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.classes')
        ->assertSee('Belum ada periode akademik')
        ->call('create')
        ->assertSet('periodId', '');
});

test('a course cannot have two classes with the same name and prodi in one period', function () {
    $this->actingAs(User::factory()->admin()->create());
    $existing = CourseClass::factory()->create(['name' => 'A', 'prodi' => 'IF']);

    Livewire::test('pages::admin.classes')
        ->call('create')
        ->set('courseId', (string) $existing->course_id)
        ->set('name', 'A')
        ->set('prodi', 'IF')
        ->call('save')
        ->assertHasErrors(['name' => 'unique']);

    expect(CourseClass::query()->count())->toBe(1);
});

test('admins can update a class without tripping its own uniqueness rule', function () {
    $this->actingAs(User::factory()->admin()->create());
    $class = CourseClass::factory()->at(Day::Senin, '07:00')->create(['name' => 'A', 'prodi' => 'IF']);

    Livewire::test('pages::admin.classes')
        ->call('edit', $class->id)
        ->assertSet('startTime', '07:00')
        ->set('day', (string) Day::Kamis->value)
        ->set('startTime', '13:30')
        ->set('endTime', '15:20')
        ->set('capacity', '')
        ->call('save')
        ->assertHasNoErrors();

    $class->refresh();

    expect($class->day)->toBe(Day::Kamis)
        ->and($class->start_time)->toBe('13:30')
        ->and($class->end_time)->toBe('15:20')
        ->and($class->capacity)->toBeNull();
});

test('deleting a class removes it from student KRS', function () {
    $this->actingAs(User::factory()->admin()->create());
    $class = CourseClass::factory()->create();
    $student = User::factory()->create();
    $student->courseClasses()->attach($class);

    Livewire::test('pages::admin.classes')
        ->call('confirmDelete', $class->id)
        ->call('delete');

    expect(CourseClass::query()->count())->toBe(0)
        ->and($student->courseClasses()->count())->toBe(0);
});
