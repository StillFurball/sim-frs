<?php

use App\Enums\Day;
use App\Enums\Term;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('krs.index'))->assertRedirect(route('login'));
});

test('admins cannot open the KRS page', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('krs.index'))->assertForbidden();
});

test('students can open the KRS page', function () {
    $this->actingAs(User::factory()->create());
    CourseClass::factory()->for(Course::factory()->state(['name' => 'Deep Learning']))->create();

    $this->get(route('krs.index'))->assertOk()->assertSee('Deep Learning');
});

test('taking a class adds it to the student KRS', function () {
    $student = User::factory()->create();
    $class = CourseClass::factory()->create();
    $this->actingAs($student);

    Livewire::test('pages::krs.index')->call('take', $class->id);

    $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_class_id' => $class->id]);
});

test('taking a clashing class shows the clash and does not enroll', function () {
    $student = User::factory()->create();
    $taken = CourseClass::factory()->at(Day::Senin, '10:00')->create();
    $clashing = CourseClass::factory()->at(Day::Senin, '11:00')->create();
    $student->courseClasses()->attach($taken);
    $this->actingAs($student);

    Livewire::test('pages::krs.index')
        ->call('take', $clashing->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && str_contains($params['slots']['text'], 'bentrok'));

    $this->assertDatabaseMissing('enrollments', ['user_id' => $student->id, 'course_class_id' => $clashing->id]);
});

test('taking a class beyond the SKS limit is rejected', function () {
    $student = User::factory()->maxSks(18)->create();
    $takenClasses = collect(['07:00', '10:00', '13:30', '15:30'])
        ->map(fn (string $start) => CourseClass::factory()->at(Day::Senin, $start)->create())
        ->concat([
            CourseClass::factory()->at(Day::Selasa, '07:00')->create(),
            CourseClass::factory()->at(Day::Selasa, '10:00')->create(),
        ]);
    $student->courseClasses()->attach($takenClasses->pluck('id')->all());
    $extra = CourseClass::factory()->at(Day::Rabu, '07:00')->create();
    $this->actingAs($student);

    Livewire::test('pages::krs.index')
        ->assertSee('Melebihi batas SKS')
        ->call('take', $extra->id);

    $this->assertDatabaseMissing('enrollments', ['user_id' => $student->id, 'course_class_id' => $extra->id]);
});

test('taking another class of a taken course moves the student to it', function () {
    $student = User::factory()->create();
    $course = Course::factory()->create();
    $classA = CourseClass::factory()->for($course)->at(Day::Senin, '07:00')->create(['name' => 'A']);
    $classB = CourseClass::factory()->for($course)->at(Day::Selasa, '07:00')->create(['name' => 'B']);
    $student->courseClasses()->attach($classA);
    $this->actingAs($student);

    Livewire::test('pages::krs.index')
        ->assertSee('Pindah ke kelas ini')
        ->call('take', $classB->id);

    expect($student->courseClasses()->pluck('course_classes.id')->all())->toBe([$classB->id]);
});

test('dropping a class removes it from the student KRS', function () {
    $student = User::factory()->create();
    $class = CourseClass::factory()->create();
    $student->courseClasses()->attach($class);
    $this->actingAs($student);

    Livewire::test('pages::krs.index')->call('drop', $class->id);

    $this->assertDatabaseMissing('enrollments', ['user_id' => $student->id, 'course_class_id' => $class->id]);
});

test('dropping only affects the authenticated student', function () {
    $student = User::factory()->create();
    $otherStudent = User::factory()->create();
    $class = CourseClass::factory()->create();
    $otherStudent->courseClasses()->attach($class);
    $this->actingAs($student);

    Livewire::test('pages::krs.index')->call('drop', $class->id);

    $this->assertDatabaseHas('enrollments', ['user_id' => $otherStudent->id, 'course_class_id' => $class->id]);
});

test('clearing the KRS removes every class of the student', function () {
    $student = User::factory()->create();
    $student->courseClasses()->attach([
        CourseClass::factory()->at(Day::Senin, '07:00')->create()->id,
        CourseClass::factory()->at(Day::Selasa, '07:00')->create()->id,
    ]);
    $this->actingAs($student);

    Livewire::test('pages::krs.index')->call('clearAll');

    $this->assertDatabaseMissing('enrollments', ['user_id' => $student->id]);
});

test('the KRS page lists only the classes of the selected period', function () {
    $active = AcademicPeriod::factory()->active()->of(2025, Term::Genap)->create();
    $past = AcademicPeriod::factory()->of(2025, Term::Ganjil)->create();
    CourseClass::factory()->inPeriod($active)->for(Course::factory()->state(['name' => 'Deep Learning']))->create();
    CourseClass::factory()->inPeriod($past)->for(Course::factory()->state(['name' => 'Grafika Komputer']))->create();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::krs.index')
        ->assertSet('periodId', $active->id)
        ->assertSee('Deep Learning')
        ->assertDontSee('Grafika Komputer')
        ->set('periodId', $past->id)
        ->assertSee('Grafika Komputer')
        ->assertDontSee('Deep Learning');
});

test('the KRS page explains when there is no academic period yet', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::krs.index')->assertSee('Belum ada periode akademik. Hubungi admin.');
});

test('a past period KRS is read-only', function () {
    AcademicPeriod::factory()->active()->of(2025, Term::Genap)->create();
    $past = AcademicPeriod::factory()->of(2025, Term::Ganjil)->create();
    $student = User::factory()->create();
    $takenInPast = CourseClass::factory()->inPeriod($past)->at(Day::Senin, '07:00')->create();
    $otherPastClass = CourseClass::factory()->inPeriod($past)->at(Day::Selasa, '07:00')->create();
    $student->courseClasses()->attach($takenInPast);
    $this->actingAs($student);

    Livewire::test('pages::krs.index', ['periodId' => $past->id])
        ->assertSee('KRS periode 2025/2026 Ganjil hanya bisa dilihat (bukan periode aktif)')
        ->assertDontSee('wire:click="take('.$otherPastClass->id.')"', false)
        ->call('take', $otherPastClass->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && str_contains($params['slots']['text'], 'sedang tidak dibuka'))
        ->call('drop', $takenInPast->id)
        ->call('clearAll');

    expect($student->courseClasses()->pluck('course_classes.id')->all())->toBe([$takenInPast->id]);
});

test('clearing the KRS keeps the enrollments of other periods', function () {
    $active = AcademicPeriod::factory()->active()->of(2025, Term::Genap)->create();
    $past = AcademicPeriod::factory()->of(2025, Term::Ganjil)->create();
    $student = User::factory()->create();
    $pastClass = CourseClass::factory()->inPeriod($past)->create();
    $student->courseClasses()->attach([$pastClass->id, CourseClass::factory()->inPeriod($active)->create()->id]);
    $this->actingAs($student);

    Livewire::test('pages::krs.index')->call('clearAll');

    expect($student->courseClasses()->pluck('course_classes.id')->all())->toBe([$pastClass->id]);
});

test('dropping a class of another period from the active period view is ignored', function () {
    AcademicPeriod::factory()->active()->of(2025, Term::Genap)->create();
    $past = AcademicPeriod::factory()->of(2025, Term::Ganjil)->create();
    $student = User::factory()->create();
    $pastClass = CourseClass::factory()->inPeriod($past)->create();
    $student->courseClasses()->attach($pastClass);
    $this->actingAs($student);

    Livewire::test('pages::krs.index')->call('drop', $pastClass->id);

    $this->assertDatabaseHas('enrollments', ['user_id' => $student->id, 'course_class_id' => $pastClass->id]);
});

test('lowering the SKS limit below the taken SKS is rejected', function () {
    $student = User::factory()->maxSks(24)->create();
    $student->courseClasses()->attach(
        collect(['07:00', '10:00', '13:30', '15:30'])
            ->map(fn (string $start) => CourseClass::factory()->at(Day::Senin, $start)->create()->id)
            ->concat([
                CourseClass::factory()->at(Day::Selasa, '07:00')->create()->id,
                CourseClass::factory()->at(Day::Selasa, '10:00')->create()->id,
                CourseClass::factory()->at(Day::Selasa, '13:30')->create()->id,
            ])
            ->all(),
    );
    $this->actingAs($student);

    Livewire::test('pages::krs.index')
        ->set('maxSks', 18)
        ->assertHasErrors('maxSks')
        ->assertSet('maxSks', 24);

    expect($student->refresh()->max_sks)->toBe(24);
});

test('changing the SKS limit to a valid value persists it', function () {
    $student = User::factory()->maxSks(24)->create();
    $this->actingAs($student);

    Livewire::test('pages::krs.index')
        ->set('maxSks', '20')
        ->assertHasNoErrors();

    expect($student->refresh()->max_sks)->toBe(20);
});
