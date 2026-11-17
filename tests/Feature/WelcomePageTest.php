<?php

use App\Enums\Term;
use App\Models\AcademicPeriod;
use App\Models\User;

test('the landing page shows the active period on its status badge', function () {
    AcademicPeriod::factory()->of(2025, Term::Genap)->active()->create();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Periode Pengisian KRS Genap 2025/2026 Aktif');
});

test('the landing page says the KRS period is closed when no period is active', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Periode pengisian KRS belum dibuka');
});

test('the landing page offers guests the simulator and the login modal', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSeeLivewire('krs-simulator')
        ->assertSee('Masuk Portal')
        ->assertSee('action="'.route('login.store').'"', false);
});

test('the landing page sends signed-in visitors to the dashboard instead of the login modal', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertOk()
        ->assertSee('href="'.route('dashboard').'"', false)
        ->assertDontSee('Masuk Portal');
});
