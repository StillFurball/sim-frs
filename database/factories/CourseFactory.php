<?php

namespace Database\Factories;

use App\Enums\CourseType;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => null,
            'name' => fake()->unique()->words(3, true),
            'semester' => 6,
            'type' => CourseType::Elective,
            'sks' => 3,
        ];
    }

    /**
     * Indicate that the course is mandatory for its semester.
     */
    public function mandatory(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'EF'.fake()->unique()->numerify('2346##'),
            'type' => CourseType::Mandatory,
        ]);
    }

    /**
     * Indicate that the course is an elective.
     */
    public function elective(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CourseType::Elective,
        ]);
    }

    /**
     * Indicate that the course is a general (SKPB) course.
     */
    public function general(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'UG'.fake()->unique()->numerify('2349##'),
            'type' => CourseType::General,
        ]);
    }
}
