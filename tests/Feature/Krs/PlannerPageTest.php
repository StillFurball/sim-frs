<?php

use App\Enums\Day;
use App\Enums\Term;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Lecturer;
use App\Models\User;
use Livewire\Livewire;

/**
 * Build a small schedule: one mandatory semester-6 course with two classes and three electives,
 * where the first elective clashes with the mandatory course's Monday class.
 *
 * @return array{mandatoryMonday: CourseClass, mandatoryTuesday: CourseClass, clashingElective: CourseClass, lecturerElective: CourseClass, thursdayElective: CourseClass, lecturer: Lecturer}
 */
function plannerSchedule(): array
{
    $mandatory = Course::factory()->mandatory()->create(['semester' => 6]);
    $lecturer = Lecturer::factory()->create();

    $lecturerElective = CourseClass::factory()->at(Day::Rabu, '10:00')->create();
    $lecturerElective->lecturers()->attach($lecturer);

    return [
        'mandatoryMonday' => CourseClass::factory()->for($mandatory)->at(Day::Senin, '07:00')->create(['name' => 'A']),
        'mandatoryTuesday' => CourseClass::factory()->for($mandatory)->at(Day::Selasa, '07:00')->create(['name' => 'B']),
        'clashingElective' => CourseClass::factory()->at(Day::Senin, '07:00')->create(),
        'lecturerElective' => $lecturerElective,
        'thursdayElective' => CourseClass::factory()->at(Day::Kamis, '10:00')->create(),
        'lecturer' => $lecturer,
    ];
}

/**
 * Get the class ids of a planner result, sorted for comparison.
 *
 * @param  array{class_ids: list<int>}  $result
 * @return list<int>
 */
function sortedClassIds(array $result): array
{
    $ids = $result['class_ids'];
    sort($ids);

    return $ids;
}

test('guests are redirected to the login page', function () {
    $this->get(route('krs.planner'))->assertRedirect(route('login'));
});

test('admins cannot open the planner', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('krs.planner'))->assertForbidden();
});

test('students can open the planner', function () {
    AcademicPeriod::factory()->active()->of(2025, Term::Genap)->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('krs.planner'))->assertOk()->assertSee('Cari kombinasi')->assertSee('2025/2026 Genap');
});

test('the planner refuses to run without an active period', function () {
    AcademicPeriod::factory()->of(2025, Term::Genap)->create();
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::krs.planner')
        ->assertSee('Belum ada periode aktif. Rencana otomatis tidak bisa dijalankan.')
        ->call('generate')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger')
        ->assertSet('results', [])
        ->assertSet('hasGenerated', false);
});

test('generating only uses classes of the active period', function () {
    $schedule = plannerSchedule();
    $past = AcademicPeriod::factory()->of(2024, Term::Genap)->create();
    $pastCourse = Course::factory()->elective()->create(['name' => 'Kuliah Periode Lalu']);
    $pastClass = CourseClass::factory()->inPeriod($past)->for($pastCourse)->at(Day::Jumat, '07:00')->create();
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::krs.planner')
        ->assertDontSee('Kuliah Periode Lalu')
        ->call('generate');

    $classIds = collect($component->get('results'))->pluck('class_ids')->flatten()->all();
    expect($classIds)->not->toBeEmpty()
        ->not->toContain($pastClass->id)
        ->and(CourseClass::whereKey($classIds)->pluck('academic_period_id')->unique()->all())
        ->toBe([$schedule['mandatoryMonday']->academic_period_id]);
});

test('generating returns conflict free combinations that include the mandatory course', function () {
    $schedule = plannerSchedule();
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::krs.planner')->call('generate');

    $component->assertHasNoErrors();
    $results = $component->get('results');
    expect(sortedClassIds($results[0]))->toBe(sortedClassIds(['class_ids' => [
        $schedule['mandatoryTuesday']->id,
        $schedule['clashingElective']->id,
        $schedule['lecturerElective']->id,
        $schedule['thursdayElective']->id,
    ]]));
    expect($results[0]['total_sks'])->toBe(12);
    foreach ($results as $result) {
        expect(array_intersect($result['class_ids'], [$schedule['mandatoryMonday']->id, $schedule['mandatoryTuesday']->id]))->toHaveCount(1);
        expect(in_array($schedule['mandatoryMonday']->id, $result['class_ids'], true) && in_array($schedule['clashingElective']->id, $result['class_ids'], true))->toBeFalse();
    }
});

test('generating never uses classes of an avoided lecturer', function () {
    $schedule = plannerSchedule();
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::krs.planner')
        ->set("lecturerPreferences.{$schedule['lecturer']->id}", 'avoided')
        ->call('generate');

    $results = $component->get('results');
    expect(sortedClassIds($results[0]))->toBe(sortedClassIds(['class_ids' => [
        $schedule['mandatoryTuesday']->id,
        $schedule['clashingElective']->id,
        $schedule['thursdayElective']->id,
    ]]));
    expect(collect($results)->pluck('class_ids')->flatten()->all())->not->toContain($schedule['lecturerElective']->id);
});

test('generating rejects an sks limit above the student limit', function () {
    plannerSchedule();
    $this->actingAs(User::factory()->maxSks(20)->create());

    $component = Livewire::test('pages::krs.planner')
        ->set('maxSks', 24)
        ->call('generate');

    $component->assertHasErrors(['maxSks' => 'in']);
    expect($component->get('results'))->toBe([]);
});

test('applying a combination replaces the student krs and redirects to the krs page', function () {
    $schedule = plannerSchedule();
    $student = User::factory()->create();
    $student->courseClasses()->attach($schedule['mandatoryMonday']);
    $this->actingAs($student);

    $component = Livewire::test('pages::krs.planner')->call('generate');
    $component->call('apply', 0);

    $component->assertHasNoErrors()->assertRedirect(route('krs.index'));
    expect($student->courseClasses()->pluck('course_classes.id')->sort()->values()->all())
        ->toBe(sortedClassIds($component->get('results')[0]));
    $this->assertDatabaseMissing('enrollments', [
        'user_id' => $student->id,
        'course_class_id' => $schedule['mandatoryMonday']->id,
    ]);
});
