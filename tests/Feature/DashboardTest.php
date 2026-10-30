<?php

use App\Enums\Term;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('students see their sks summary and not the admin statistics', function () {
    $student = User::factory()->maxSks(22)->create();
    $class = CourseClass::factory()->for(Course::factory()->create(['name' => 'Deep Learning', 'sks' => 3]))->create();
    $student->courseClasses()->attach($class);
    $this->actingAs($student);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('3 / 22 SKS')
        ->assertSee('Deep Learning')
        ->assertDontSee('Kelas terpopuler');
});

test('the dashboard shows the active period', function () {
    AcademicPeriod::factory()->active()->of(2025, Term::Genap)->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertOk()->assertSee('Periode aktif: 2025/2026 Genap');
});

test('the dashboard explains when no period is active', function () {
    AcademicPeriod::factory()->of(2025, Term::Genap)->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertOk()->assertSee('Belum ada periode aktif');
});

test('students only see the KRS of the active period', function () {
    $active = AcademicPeriod::factory()->active()->of(2025, Term::Genap)->create();
    $past = AcademicPeriod::factory()->of(2025, Term::Ganjil)->create();
    $student = User::factory()->maxSks(24)->create();
    $student->courseClasses()->attach([
        CourseClass::factory()->inPeriod($active)->for(Course::factory()->create(['name' => 'Deep Learning', 'sks' => 3]))->create()->id,
        CourseClass::factory()->inPeriod($past)->for(Course::factory()->create(['name' => 'Grafika Komputer', 'sks' => 4]))->create()->id,
    ]);
    $this->actingAs($student);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('3 / 24 SKS')
        ->assertSee('Deep Learning')
        ->assertDontSee('Grafika Komputer');
});

test('admin statistics for classes and enrollments only count the active period', function () {
    $active = AcademicPeriod::factory()->active()->of(2025, Term::Genap)->create();
    $past = AcademicPeriod::factory()->of(2025, Term::Ganjil)->create();
    $activeClass = CourseClass::factory()->inPeriod($active)->create();
    $pastClass = CourseClass::factory()->inPeriod($past)->for(Course::factory()->create(['name' => 'Grafika Komputer']))->create();
    $student = User::factory()->create();
    $student->courseClasses()->attach([$activeClass->id, $pastClass->id]);
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('data-test="stat-courses">2</')
        ->assertSeeHtml('data-test="stat-classes">1</')
        ->assertSeeHtml('data-test="stat-enrollments">1</')
        ->assertDontSee('Grafika Komputer');
});

test('admins see academic statistics and the most popular classes', function () {
    $popular = CourseClass::factory()->capacity(40)->for(Course::factory()->create(['name' => 'Text Mining']))->create();
    CourseClass::factory()->create();
    User::factory()->count(2)->create()->each(fn (User $student) => $student->courseClasses()->attach($popular));
    $this->actingAs(User::factory()->admin()->create());

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Kelas terpopuler')
        ->assertSeeInOrder(['Text Mining', '2 / 40'])
        ->assertSeeHtml('data-test="stat-classes">2</')
        ->assertSeeHtml('data-test="stat-students">2</')
        ->assertSeeHtml('data-test="stat-enrollments">2</');
});
