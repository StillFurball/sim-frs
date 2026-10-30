<?php

namespace Database\Factories;

use App\Enums\Day;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseClass>
 */
class CourseClassFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->randomElement(['07:00', '10:00', '13:30', '15:30']);

        return [
            'academic_period_id' => fn (): int => AcademicPeriod::current()->id ?? AcademicPeriod::factory()->active()->create()->id,
            'course_id' => Course::factory(),
            'name' => fake()->unique()->bothify('?#'),
            'prodi' => 'IF',
            'day' => fake()->randomElement(Day::cases()),
            'start_time' => $start,
            'end_time' => self::endTimeFor($start),
            'room' => 'IF-'.fake()->numberBetween(101, 112),
            'capacity' => 50,
            'language' => 'Indonesia',
            'ramadhan_schedule' => null,
        ];
    }

    /**
     * Schedule the class on a specific day and start time, lasting two 50-minute slots.
     */
    public function at(Day $day, string $start, ?string $end = null): static
    {
        return $this->state(fn (array $attributes) => [
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end ?? self::endTimeFor($start),
        ]);
    }

    /**
     * Schedule the class in a specific academic period.
     */
    public function inPeriod(AcademicPeriod $period): static
    {
        return $this->state(fn (array $attributes) => [
            'academic_period_id' => $period->id,
        ]);
    }

    /**
     * Give the class a specific capacity.
     */
    public function capacity(?int $capacity): static
    {
        return $this->state(fn (array $attributes) => [
            'capacity' => $capacity,
        ]);
    }

    /**
     * Calculate the end time of a two-slot block starting at the given time.
     */
    private static function endTimeFor(string $start): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $start));
        $total = $hour * 60 + $minute + 110;

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }
}
