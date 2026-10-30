<?php

namespace App\Support;

use App\Models\CourseClass;

/**
 * One conflict-free set of classes proposed by the KRS planner.
 */
final readonly class ScheduleCombination
{
    /**
     * @param  list<CourseClass>  $classes
     */
    public function __construct(
        public array $classes,
        public float $score,
        public int $totalSks,
        public int $electiveCount,
        public int $priorityCount,
        public int $preferredLecturerCount,
        public int $dayCount,
        public int $gapMinutes,
    ) {}

    /**
     * Get the IDs of the classes in the combination.
     *
     * @return list<int>
     */
    public function classIds(): array
    {
        return array_map(fn (CourseClass $class): int => $class->id, $this->classes);
    }

    /**
     * Serialize the combination into plain data that can live in a Livewire property.
     *
     * @return array{class_ids: list<int>, score: float, total_sks: int, elective_count: int, priority_count: int, preferred_lecturer_count: int, day_count: int, gap_minutes: int}
     */
    public function toArray(): array
    {
        return [
            'class_ids' => $this->classIds(),
            'score' => round($this->score, 1),
            'total_sks' => $this->totalSks,
            'elective_count' => $this->electiveCount,
            'priority_count' => $this->priorityCount,
            'preferred_lecturer_count' => $this->preferredLecturerCount,
            'day_count' => $this->dayCount,
            'gap_minutes' => $this->gapMinutes,
        ];
    }
}
