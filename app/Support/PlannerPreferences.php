<?php

namespace App\Support;

/**
 * The constraints and preferences a student gives to the automatic KRS planner.
 *
 * Hard constraints remove classes or combinations; soft preferences only change the ranking.
 */
final readonly class PlannerPreferences
{
    /**
     * @param  int  $maxSks  Upper SKS bound of every combination.
     * @param  int  $minSks  Combinations below this SKS total are discarded.
     * @param  int|null  $semester  Every mandatory course of this semester must be taken (unless excluded).
     * @param  int  $minElectives  Minimum number of elective (pilihan) courses.
     * @param  int|null  $maxElectives  Maximum number of elective courses, or null for no limit.
     * @param  list<int>  $requiredCourseIds  Courses that must be in every combination.
     * @param  list<int>  $priorityCourseIds  Courses that should be included when possible (soft).
     * @param  list<int>  $excludedCourseIds  Courses that must never be included.
     * @param  list<int>  $avoidedLecturerIds  Classes taught by any of these lecturers are never used.
     * @param  list<int>  $preferredLecturerIds  Classes taught by these lecturers rank higher (soft).
     * @param  list<int>  $avoidedDays  Day enum values without any class.
     * @param  string|null  $earliestStart  No class may start before this time ("HH:MM").
     * @param  string|null  $latestEnd  No class may end after this time ("HH:MM").
     * @param  string|null  $language  Only classes taught in this language, or null for any.
     * @param  list<string>  $prodi  Only classes opened for at least one of these study programs; empty for any.
     * @param  bool  $includeGeneral  Allow general (SKPB) courses as fillers.
     * @param  bool  $includeOtherMandatory  Allow mandatory courses of other semesters as fillers.
     * @param  bool  $skipFullClasses  Never use classes that have no free seat.
     * @param  bool  $keepCurrentClasses  Lock the classes already in the student's KRS.
     * @param  bool  $compactDays  Prefer combinations with fewer days on campus.
     * @param  bool  $minimizeGaps  Prefer combinations with less idle time between classes.
     * @param  int  $limit  Maximum number of combinations returned.
     */
    public function __construct(
        public int $maxSks = 24,
        public int $minSks = 0,
        public ?int $semester = null,
        public int $minElectives = 2,
        public ?int $maxElectives = null,
        public array $requiredCourseIds = [],
        public array $priorityCourseIds = [],
        public array $excludedCourseIds = [],
        public array $avoidedLecturerIds = [],
        public array $preferredLecturerIds = [],
        public array $avoidedDays = [],
        public ?string $earliestStart = null,
        public ?string $latestEnd = null,
        public ?string $language = null,
        public array $prodi = [],
        public bool $includeGeneral = false,
        public bool $includeOtherMandatory = false,
        public bool $skipFullClasses = true,
        public bool $keepCurrentClasses = false,
        public bool $compactDays = false,
        public bool $minimizeGaps = false,
        public int $limit = 5,
    ) {}
}
