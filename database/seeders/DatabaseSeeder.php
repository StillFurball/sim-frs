<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Admin Akademik',
            'email' => 'admin@krs.test',
        ]);

        User::factory()->create([
            'name' => 'Mahasiswa Demo',
            'email' => 'mahasiswa@krs.test',
            'nrp' => '5025231001',
            'max_sks' => 24,
        ]);

        User::factory()->maxSks(20)->create([
            'name' => 'Mahasiswa SKS 20',
            'email' => 'mahasiswa20@krs.test',
            'nrp' => '5025231002',
        ]);

        $this->call(ScheduleSeeder::class);
    }
}
