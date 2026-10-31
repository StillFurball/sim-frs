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
     * Each class keeps the SKS it had in its period, so later changes to the course catalogue leave past KRS intact.
     * Existing classes take the current SKS of their course.
     */
    public function up(): void
    {
        Schema::table('course_classes', function (Blueprint $table) {
            $table->unsignedTinyInteger('sks')->nullable()->after('course_id');
        });

        DB::table('course_classes')->update([
            'sks' => DB::table('courses')->select('sks')->whereColumn('courses.id', 'course_classes.course_id'),
        ]);

        Schema::table('course_classes', function (Blueprint $table) {
            $table->unsignedTinyInteger('sks')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_classes', function (Blueprint $table) {
            $table->dropColumn('sks');
        });
    }
};
