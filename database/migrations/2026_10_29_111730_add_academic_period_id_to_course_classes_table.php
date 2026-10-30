<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Classes that already exist were imported from the 2025/2026 Genap schedule, so they are moved into that period.
     */
    public function up(): void
    {
        Schema::table('course_classes', function (Blueprint $table) {
            $table->foreignId('academic_period_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        if (DB::table('course_classes')->exists()) {
            $periodId = DB::table('academic_periods')->where(['start_year' => 2025, 'term' => 2])->value('id')
                ?? DB::table('academic_periods')->insertGetId([
                    'start_year' => 2025,
                    'term' => 2,
                    'is_active' => ! DB::table('academic_periods')->where('is_active', true)->exists(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('course_classes')->whereNull('academic_period_id')->update(['academic_period_id' => $periodId]);
        }

        Schema::table('course_classes', function (Blueprint $table) {
            $table->foreignId('academic_period_id')->nullable(false)->change();
            $table->dropUnique(['course_id', 'name', 'prodi']);
            $table->unique(['academic_period_id', 'course_id', 'name', 'prodi']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_classes', function (Blueprint $table) {
            $table->dropUnique(['academic_period_id', 'course_id', 'name', 'prodi']);
            $table->dropConstrainedForeignId('academic_period_id');
            $table->unique(['course_id', 'name', 'prodi']);
        });
    }
};
