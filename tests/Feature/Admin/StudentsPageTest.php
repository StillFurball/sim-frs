<?php

use App\Enums\Term;
use App\Enums\UserRole;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('admin.students'))->assertRedirect(route('login'));
});

test('students cannot open the students page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.students'))->assertForbidden();
});

test('admins see students with their taken SKS in the active period but not other admins', function () {
    $this->actingAs(User::factory()->admin()->create());
    $active = AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();
    $previous = AcademicPeriod::factory()->of(2025, Term::Ganjil)->create();
    User::factory()->admin()->create(['name' => 'Admin Rahasia']);
    $student = User::factory()->create();
    $student->courseClasses()->attach([
        CourseClass::factory()->inPeriod($active)->for(Course::factory()->state(['sks' => 3]))->create()->id,
        CourseClass::factory()->inPeriod($active)->for(Course::factory()->state(['sks' => 2]))->create()->id,
        CourseClass::factory()->inPeriod($previous)->for(Course::factory()->state(['sks' => 4]))->create()->id,
    ]);

    $this->get(route('admin.students'))
        ->assertOk()
        ->assertSee($student->name)
        ->assertSee('SKS Diambil (2025/2026 Genap)')
        ->assertSee('5 SKS')
        ->assertDontSee('9 SKS')
        ->assertDontSee('Admin Rahasia');
});

test('admins can create a verified student account', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.students')
        ->call('create')
        ->set('name', 'Budi Santoso')
        ->set('email', 'budi@example.com')
        ->set('nrp', '5025231999')
        ->set('maxSks', '20')
        ->set('password', 'password123')
        ->call('save')
        ->assertHasNoErrors();

    $student = User::query()->where('email', 'budi@example.com')->sole();

    expect($student->role)->toBe(UserRole::Student)
        ->and($student->nrp)->toBe('5025231999')
        ->and($student->max_sks)->toBe(20)
        ->and($student->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password123', $student->password))->toBeTrue();
});

test('the SKS limit must be one of the allowed values', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.students')
        ->call('create')
        ->set('name', 'Budi')
        ->set('email', 'budi@example.com')
        ->set('maxSks', '21')
        ->set('password', 'password123')
        ->call('save')
        ->assertHasErrors(['maxSks' => 'in']);
});

test('the SKS limit cannot be lowered below the SKS already taken', function () {
    $this->actingAs(User::factory()->admin()->create());
    $student = User::factory()->maxSks(24)->create();
    $student->courseClasses()->attach(CourseClass::factory()->count(7)->create()->pluck('id'));

    Livewire::test('pages::admin.students')
        ->call('edit', $student->id)
        ->set('maxSks', '18')
        ->call('save')
        ->assertHasErrors(['maxSks']);

    expect($student->refresh()->max_sks)->toBe(24);
});

test('KRS from other periods does not block lowering the SKS limit', function () {
    $this->actingAs(User::factory()->admin()->create());
    AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();
    $previous = AcademicPeriod::factory()->of(2025, Term::Ganjil)->create();
    $student = User::factory()->maxSks(24)->create();
    $student->courseClasses()->attach(CourseClass::factory()->count(7)->inPeriod($previous)->create()->pluck('id'));

    Livewire::test('pages::admin.students')
        ->call('edit', $student->id)
        ->set('maxSks', '18')
        ->call('save')
        ->assertHasNoErrors();

    expect($student->refresh()->max_sks)->toBe(18);
});

test('admins can update a student without changing the password', function () {
    $this->actingAs(User::factory()->admin()->create());
    $student = User::factory()->maxSks(24)->create();
    $originalPassword = $student->password;

    Livewire::test('pages::admin.students')
        ->call('edit', $student->id)
        ->set('name', 'Nama Baru')
        ->set('maxSks', '22')
        ->call('save')
        ->assertHasNoErrors();

    $student->refresh();

    expect($student->name)->toBe('Nama Baru')
        ->and($student->max_sks)->toBe(22)
        ->and($student->password)->toBe($originalPassword);
});

test('admins can delete a student', function () {
    $this->actingAs(User::factory()->admin()->create());
    $student = User::factory()->create();

    Livewire::test('pages::admin.students')
        ->call('confirmDelete', $student->id)
        ->call('delete');

    expect($student->fresh())->toBeNull();
});

test('admins cannot manage admin accounts through the students page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $otherAdmin = User::factory()->admin()->create();

    Livewire::test('pages::admin.students')
        ->call('confirmDelete', $otherAdmin->id)
        ->call('delete')
        ->assertNotFound();

    expect($otherAdmin->fresh())->not->toBeNull();
});

test('admins can drop a class from the KRS of a student', function () {
    $this->actingAs(User::factory()->admin()->create());
    $student = User::factory()->create();
    [$dropped, $kept] = CourseClass::factory()->count(2)->create();
    $student->courseClasses()->attach([$dropped->id, $kept->id]);

    Livewire::test('pages::admin.students')
        ->call('showKrs', $student->id)
        ->assertSee($dropped->course->name)
        ->call('dropClass', $dropped->id);

    expect($student->courseClasses()->pluck('course_classes.id')->all())->toBe([$kept->id]);
});

test('the KRS modal shows the classes of the selected period', function () {
    $this->actingAs(User::factory()->admin()->create());
    $active = AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();
    $previous = AcademicPeriod::factory()->of(2025, Term::Ganjil)->create();
    $student = User::factory()->create();
    $student->courseClasses()->attach([
        CourseClass::factory()->inPeriod($active)->for(Course::factory()->state(['name' => 'Kuliah Genap']))->create()->id,
        CourseClass::factory()->inPeriod($previous)->for(Course::factory()->state(['name' => 'Kuliah Ganjil']))->create()->id,
    ]);

    Livewire::test('pages::admin.students')
        ->call('showKrs', $student->id)
        ->assertSet('krsPeriodId', (string) $active->id)
        ->assertSee('Kuliah Genap')
        ->assertDontSee('Kuliah Ganjil')
        ->set('krsPeriodId', (string) $previous->id)
        ->assertSee('Kuliah Ganjil')
        ->assertDontSee('Kuliah Genap');
});
