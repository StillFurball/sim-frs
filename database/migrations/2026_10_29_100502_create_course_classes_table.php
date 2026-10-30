<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('course_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('prodi')->default('');
            $table->unsignedTinyInteger('day');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('room')->nullable();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('language')->default('Indonesia');
            $table->string('ramadhan_schedule')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'name', 'prodi']);
            $table->index(['day', 'start_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_classes');
    }
};
