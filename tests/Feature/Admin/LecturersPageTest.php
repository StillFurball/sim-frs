<?php

use App\Models\CourseClass;
use App\Models\Lecturer;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('admin.lecturers'))->assertRedirect(route('login'));
});

test('students cannot open the lecturers page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.lecturers'))->assertForbidden();
});

test('admins can view the lecturers page', function () {
    $this->actingAs(User::factory()->admin()->create());
    $lecturer = Lecturer::factory()->create();

    $this->get(route('admin.lecturers'))->assertOk()->assertSee($lecturer->code);
});

test('admins can create a lecturer', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.lecturers')
        ->call('create')
        ->set('code', 'DH')
        ->set('name', 'Dosen Hebat')
        ->call('save')
        ->assertHasNoErrors();

    $lecturer = Lecturer::query()->sole();

    expect($lecturer->code)->toBe('DH')
        ->and($lecturer->name)->toBe('Dosen Hebat');
});

test('lecturer codes must be unique', function () {
    $this->actingAs(User::factory()->admin()->create());
    $existing = Lecturer::factory()->create();

    Livewire::test('pages::admin.lecturers')
        ->call('create')
        ->set('code', $existing->code)
        ->call('save')
        ->assertHasErrors(['code' => 'unique'])
        ->assertSee('Kode dosen sudah digunakan.');
});

test('admins can update a lecturer', function () {
    $this->actingAs(User::factory()->admin()->create());
    $lecturer = Lecturer::factory()->create(['code' => 'AB', 'name' => null]);

    Livewire::test('pages::admin.lecturers')
        ->call('edit', $lecturer->id)
        ->set('name', 'Andi Budi')
        ->call('save')
        ->assertHasNoErrors();

    expect($lecturer->refresh()->name)->toBe('Andi Budi')
        ->and($lecturer->code)->toBe('AB');
});

test('deleting a lecturer detaches them but keeps their classes', function () {
    $this->actingAs(User::factory()->admin()->create());
    $lecturer = Lecturer::factory()->create();
    $class = CourseClass::factory()->create();
    $class->lecturers()->attach($lecturer);

    Livewire::test('pages::admin.lecturers')
        ->call('confirmDelete', $lecturer->id)
        ->call('delete');

    expect(Lecturer::query()->count())->toBe(0)
        ->and($class->fresh())->not->toBeNull()
        ->and($class->lecturers()->count())->toBe(0);
});
