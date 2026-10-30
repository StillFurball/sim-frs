<?php

namespace Database\Seeders;

use App\Actions\Krs\ImportScheduleSpreadsheet;
use App\Enums\Term;
use App\Models\AcademicPeriod;
use Illuminate\Database\Seeder;

class ScheduleSeeder extends Seeder
{
    /**
     * Create the recent academic periods and import the sample IF schedule (Semester 6 & 8, Genap 2025/2026).
     */
    public function run(ImportScheduleSpreadsheet $importSchedule): void
    {
        foreach ([[2024, Term::Ganjil], [2024, Term::Genap], [2025, Term::Ganjil], [2025, Term::Genap]] as [$startYear, $term]) {
            AcademicPeriod::query()->firstOrCreate(['start_year' => $startYear, 'term' => $term]);
        }

        $period = AcademicPeriod::query()->where(['start_year' => 2025, 'term' => Term::Genap])->sole();
        $period->activate();

        $result = $importSchedule->handle(database_path('seeders/data/jadwal-if-genap-2025-2026.xlsx'), $period);

        $this->command->info("Imported {$result->classesImported()} classes ({$result->coursesCreated} new courses) into {$period->label()}.");
    }
}
