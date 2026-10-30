<?php

use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::middleware('can:take-courses')->group(function () {
        Route::livewire('krs', 'pages::krs.index')->name('krs.index');
        Route::livewire('krs/rencana-otomatis', 'pages::krs.planner')->name('krs.planner');
    });

    Route::middleware('can:manage-academics')->prefix('admin')->name('admin.')->group(function () {
        Route::livewire('periode-akademik', 'pages::admin.periods')->name('periods');
        Route::livewire('mata-kuliah', 'pages::admin.courses')->name('courses');
        Route::livewire('kelas', 'pages::admin.classes')->name('classes');
        Route::livewire('dosen', 'pages::admin.lecturers')->name('lecturers');
        Route::livewire('mahasiswa', 'pages::admin.students')->name('students');
        Route::livewire('import-jadwal', 'pages::admin.import')->name('import');
    });
});

require __DIR__.'/settings.php';
