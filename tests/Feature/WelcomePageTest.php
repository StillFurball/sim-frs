<?php

use App\Enums\Day;
use App\Enums\Term;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;

test('the trial card offers the active period classes and leaves out general courses', function () {
    $period = AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();
    CourseClass::factory()->inPeriod($period)->for(Course::factory()->mandatory()->state(['name' => 'Pemrograman Perangkat Bergerak']))->at(Day::Senin, '10:00')->create(['name' => 'A']);
    CourseClass::factory()->inPeriod($period)->for(Course::factory()->general()->state(['name' => 'Kewarganegaraan']))->create();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Semester Genap 2025/2026')
        ->assertSee('Pemrograman Perangkat Bergerak')
        ->assertSee('PPB kelas A', false)
        ->assertDontSee('Kewarganegaraan');
});

test('the trial card leaves out classes from inactive periods', function () {
    AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();
    $oldPeriod = AcademicPeriod::factory()->of(2024, Term::Ganjil)->create();
    CourseClass::factory()->inPeriod($oldPeriod)->for(Course::factory()->state(['name' => 'Teknik Peramalan']))->create();

    $this->get(route('home'))->assertOk()->assertDontSee('Teknik Peramalan');
});

test('the trial card never opens with clashing classes filled in', function () {
    $period = AcademicPeriod::factory()->active()->create();
    $first = CourseClass::factory()->inPeriod($period)->at(Day::Senin, '10:00')->create();
    $clashing = CourseClass::factory()->inPeriod($period)->at(Day::Senin, '10:50')->create();
    $free = CourseClass::factory()->inPeriod($period)->at(Day::Selasa, '10:00')->create();

    $preselected = $this->get(route('home'))->assertOk()->viewData('preselectedClassIds');

    expect($preselected)->toContain($free->id)
        ->and(array_intersect($preselected, [$first->id, $clashing->id]))->toHaveCount(1);
});

test('the landing page explains an empty schedule when no period is active', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Jadwal semester ini belum diunggah');
});
