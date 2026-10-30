<?php

namespace Database\Factories;

use App\Enums\Term;
use App\Models\AcademicPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicPeriod>
 */
class AcademicPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Random periods stay clear of 2000–2029 so they never collide with realistic years given through of().
        [$startYear, $term] = fake()->unique()->randomElement(
            collect(range(2030, 2099))->crossJoin(Term::cases())->all(),
        );

        return [
            'start_year' => $startYear,
            'term' => $term,
            'is_active' => false,
        ];
    }

    /**
     * Indicate that the period is the active one.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Give the period a specific academic year and term.
     */
    public function of(int $startYear, Term $term): static
    {
        return $this->state(fn (array $attributes) => [
            'start_year' => $startYear,
            'term' => $term,
        ]);
    }
}
