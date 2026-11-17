<?php

use Livewire\Livewire;

test('taking a course adds its SKS and uses one seat', function () {
    Livewire::test('krs-simulator')
        ->call('take', 'SD')
        ->assertSet('taken', ['SD'])
        ->assertSee('3 / 24 SKS')
        ->assertSee('sisa 4 kursi');
});

test('a course that clashes with a taken one is rejected with a warning', function () {
    Livewire::test('krs-simulator')
        ->call('take', 'SD')
        ->call('take', 'SO')
        ->assertSet('taken', ['SD'])
        ->assertSee('Sistem Operasi bentrok dengan Struktur Data');
});

test('dropping a course gives the seat back and clears the warning', function () {
    Livewire::test('krs-simulator')
        ->call('take', 'SD')
        ->call('take', 'SO')
        ->call('drop', 'SD')
        ->assertSet('taken', [])
        ->assertSet('clashWarning', null)
        ->assertSee('0 / 24 SKS')
        ->assertSee('sisa 5 kursi');
});

test('an unknown course code is ignored', function () {
    Livewire::test('krs-simulator')
        ->call('take', 'XX')
        ->assertSet('taken', []);
});

test('the recommended order puts required courses with the fewest seats first and clashing ones last', function () {
    $simulator = Livewire::test('krs-simulator');

    expect(array_column($simulator->get('recommended'), 'skip', 'code'))
        ->toBe(['BD' => false, 'SD' => false, 'JK' => false, 'SO' => true]);

    $simulator->call('take', 'SO');

    expect(array_column($simulator->get('recommended'), 'skip', 'code'))
        ->toBe(['BD' => false, 'JK' => false, 'SD' => true]);
});
