<?php

use App\Enums\Term;
use App\Models\AcademicPeriod;
use App\Models\CourseClass;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('admin.periods'))->assertRedirect(route('login'));
});

test('students cannot open the periods page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.periods'))->assertForbidden();
});

test('admins see every period with its class and KRS counts', function () {
    $this->actingAs(User::factory()->admin()->create());
    $period = AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();
    $classes = CourseClass::factory()->count(2)->inPeriod($period)->create();
    User::factory()->create()->courseClasses()->attach($classes->pluck('id'));
    User::factory()->create()->courseClasses()->attach($classes->first());

    $periods = Livewire::test('pages::admin.periods')
        ->assertSee('2025/2026')
        ->assertSee('Genap')
        ->assertSee('Aktif')
        ->get('periods');

    expect($periods->sole()->classes_count)->toBe(2)
        ->and((int) $periods->sole()->enrollments_count)->toBe(3);
});

test('admins can create a period and make it active', function () {
    $this->actingAs(User::factory()->admin()->create());
    $previous = AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();

    Livewire::test('pages::admin.periods')
        ->call('create')
        ->set('startYear', '2026')
        ->set('term', (string) Term::Ganjil->value)
        ->set('makeActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $period = AcademicPeriod::query()->where('start_year', 2026)->sole();

    expect($period->term)->toBe(Term::Ganjil)
        ->and($period->is_active)->toBeTrue()
        ->and($previous->refresh()->is_active)->toBeFalse();
});

test('a period cannot be created twice', function () {
    $this->actingAs(User::factory()->admin()->create());
    AcademicPeriod::factory()->of(2025, Term::Genap)->create();

    $component = Livewire::test('pages::admin.periods')
        ->call('create')
        ->set('startYear', '2025')
        ->set('term', (string) Term::Genap->value)
        ->call('save')
        ->assertHasErrors(['startYear' => 'unique']);

    expect($component->errors()->first('startYear'))->toBe('Periode 2025/2026 Genap sudah ada.')
        ->and(AcademicPeriod::query()->count())->toBe(1);
});

test('admins can update a period without tripping its own uniqueness rule', function () {
    $this->actingAs(User::factory()->admin()->create());
    $period = AcademicPeriod::factory()->of(2025, Term::Genap)->create();

    Livewire::test('pages::admin.periods')
        ->call('edit', $period->id)
        ->assertSet('startYear', '2025')
        ->set('term', (string) Term::Ganjil->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($period->refresh()->term)->toBe(Term::Ganjil);
});

test('activating a period makes it the only active one', function () {
    $this->actingAs(User::factory()->admin()->create());
    $previous = AcademicPeriod::factory()->of(2025, Term::Ganjil)->active()->create();
    $next = AcademicPeriod::factory()->of(2025, Term::Genap)->create();

    Livewire::test('pages::admin.periods')->call('activate', $next->id);

    expect($next->refresh()->is_active)->toBeTrue()
        ->and($previous->refresh()->is_active)->toBeFalse()
        ->and(AcademicPeriod::query()->where('is_active', true)->count())->toBe(1);
});

test('deleting a period removes its classes and their enrollments', function () {
    $this->actingAs(User::factory()->admin()->create());
    $active = AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();
    $old = AcademicPeriod::factory()->of(2024, Term::Genap)->create();
    $oldClass = CourseClass::factory()->inPeriod($old)->create();
    $activeClass = CourseClass::factory()->inPeriod($active)->create();
    $student = User::factory()->create();
    $student->courseClasses()->attach([$oldClass->id, $activeClass->id]);

    Livewire::test('pages::admin.periods')
        ->call('confirmDelete', $old->id)
        ->assertSee('1 kelas dan 1 pengambilan KRS')
        ->call('delete');

    $this->assertModelMissing($old);
    $this->assertModelMissing($oldClass);
    $this->assertModelExists($activeClass);
    expect($student->courseClasses()->pluck('course_classes.id')->all())->toBe([$activeClass->id]);
});

test('the active period cannot be deleted', function () {
    $this->actingAs(User::factory()->admin()->create());
    $active = AcademicPeriod::factory()->active()->create();

    Livewire::test('pages::admin.periods')
        ->call('confirmDelete', $active->id)
        ->call('delete');

    $this->assertModelExists($active);
});

test('the academic year form suggests the year after the newest period with both terms selected', function () {
    $this->actingAs(User::factory()->admin()->create());
    AcademicPeriod::factory()->of(2025, Term::Genap)->create();

    Livewire::test('pages::admin.periods')
        ->call('createYear')
        ->assertSet('yearStart', '2026')
        ->assertSet('yearTerms', ['1', '2'])
        ->assertSet('activateTerm', '');
});

test('admins can create a new academic year with both terms and activate one', function () {
    $this->actingAs(User::factory()->admin()->create());
    $previous = AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();

    Livewire::test('pages::admin.periods')
        ->call('createYear')
        ->set('yearStart', '2026')
        ->set('activateTerm', (string) Term::Ganjil->value)
        ->call('saveYear')
        ->assertHasNoErrors();

    $created = AcademicPeriod::query()->where('start_year', 2026)->orderBy('term')->get();

    expect($created->map(fn (AcademicPeriod $period) => $period->label())->all())->toBe(['2026/2027 Ganjil', '2026/2027 Genap'])
        ->and(AcademicPeriod::current()?->label())->toBe('2026/2027 Ganjil')
        ->and($previous->refresh()->is_active)->toBeFalse();
});

test('creating an academic year skips the terms that already exist', function () {
    $this->actingAs(User::factory()->admin()->create());
    $existing = AcademicPeriod::factory()->of(2026, Term::Ganjil)->create();

    Livewire::test('pages::admin.periods')
        ->call('createYear')
        ->set('yearStart', '2026')
        ->call('saveYear')
        ->assertHasNoErrors();

    expect(AcademicPeriod::query()->where('start_year', 2026)->count())->toBe(2)
        ->and(AcademicPeriod::query()->where('start_year', 2026)->where('term', Term::Ganjil)->sole()->is($existing))->toBeTrue()
        ->and(AcademicPeriod::current())->toBeNull();
});

test('creating an academic year whose terms all exist is rejected', function () {
    $this->actingAs(User::factory()->admin()->create());
    AcademicPeriod::factory()->of(2026, Term::Ganjil)->create();
    AcademicPeriod::factory()->of(2026, Term::Genap)->create();

    $component = Livewire::test('pages::admin.periods')
        ->call('createYear')
        ->set('yearStart', '2026')
        ->call('saveYear')
        ->assertHasErrors('yearStart');

    expect($component->errors()->first('yearStart'))->toBe('Tahun ajaran 2026/2027 sudah memiliki semester Ganjil & Genap.')
        ->and(AcademicPeriod::query()->count())->toBe(2);
});

test('creating an academic year can be limited to one term', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.periods')
        ->call('createYear')
        ->set('yearStart', '2027')
        ->set('yearTerms', [(string) Term::Genap->value])
        ->call('saveYear')
        ->assertHasNoErrors();

    expect(AcademicPeriod::query()->sole()->label())->toBe('2027/2028 Genap');
});

test('the academic year form validates its input', function (array $input, string $field, string $message) {
    $this->actingAs(User::factory()->admin()->create());

    $component = Livewire::test('pages::admin.periods')->call('createYear');

    foreach ($input as $property => $value) {
        $component->set($property, $value);
    }

    $component->call('saveYear')->assertHasErrors($field);

    expect($component->errors()->first($field))->toBe($message)
        ->and(AcademicPeriod::query()->count())->toBe(0);
})->with([
    'year before 2000' => [['yearStart' => '1999'], 'yearStart', 'Tahun ajaran minimal 2000.'],
    'no term selected' => [['yearStart' => '2026', 'yearTerms' => []], 'yearTerms', 'Pilih minimal satu semester.'],
    'activating a term that is not created' => [['yearStart' => '2026', 'yearTerms' => ['1'], 'activateTerm' => '2'], 'activateTerm', 'Semester yang diaktifkan harus termasuk semester yang dibuat.'],
]);

test('students cannot create an academic year', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::admin.periods')
        ->set('yearStart', '2026')
        ->set('yearTerms', ['1', '2'])
        ->call('saveYear')
        ->assertForbidden();

    expect(AcademicPeriod::query()->count())->toBe(0);
});
