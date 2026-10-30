<?php

namespace App\Actions\Krs;

use App\Enums\CourseType;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\User;
use App\Support\PlannerPreferences;
use App\Support\PlannerResult;
use App\Support\ScheduleCombination;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Str;

/**
 * Search for the best conflict-free KRS combinations for a student.
 *
 * Every required course (the semester's mandatory courses, explicitly required courses and locked classes) gets
 * exactly one class; optional courses (electives and, when allowed, general or other-semester mandatory courses)
 * are added on top.
 *
 * The search is a depth-first branch-and-bound that walks the candidate classes in chronological order and decides
 * for each one whether to take it. Walking in time order makes clash detection a single comparison per day and makes
 * the day and idle-time penalties grow monotonically, which keeps the upper bound tight and the search fast. The best
 * combination is kept for each distinct set of courses, so the results show genuinely different study plans.
 */
class GenerateScheduleCombinations
{
    private const int SKS_WEIGHT = 10;

    private const int PRIORITY_WEIGHT = 40;

    private const int PREFERRED_LECTURER_WEIGHT = 8;

    private const int COMPACT_DAY_WEIGHT = 15;

    private const float GAP_HOUR_WEIGHT = 5.0;

    /**
     * Tie-breaking weights used when the student did not ask for compact days or minimal gaps.
     */
    private const float DEFAULT_DAY_WEIGHT = 0.5;

    private const float DEFAULT_GAP_HOUR_WEIGHT = 0.25;

    /**
     * Breaks this short (e.g. 08:50 → 09:00) do not count as idle time.
     */
    private const int IGNORED_GAP_MINUTES = 10;

    /**
     * Maximum number of search nodes before the search stops with the best results found so far.
     */
    private const int NODE_BUDGET = 1_500_000;

    /**
     * Candidate classes sorted by day and start time.
     *
     * @var list<array{id: int, course: int, day: int, start: int, end: int, preferred: bool, priority: bool}>
     */
    private array $pool = [];

    /**
     * Courses in the search, indexed by a dense course index.
     *
     * @var list<array{id: int, sks: int, elective: bool, priority: bool, required: bool, lastPosition: int, lastPreferredPosition: int}>
     */
    private array $courses = [];

    /**
     * Best combination per course set, keyed by the sorted course IDs.
     *
     * @var array<string, array{key: string, score: float, classes: list<int>, sks: int, electives: int, priorities: int, preferred: int, days: int, gaps: int}>
     */
    private array $best = [];

    /**
     * Pool positions of the classes currently chosen.
     *
     * @var list<int>
     */
    private array $chosen = [];

    /**
     * Whether each course (by dense index) already has a chosen class.
     *
     * @var array<int, bool>
     */
    private array $courseTaken = [];

    /**
     * End time (in minutes) of the last chosen class per day.
     *
     * @var array<int, int>
     */
    private array $dayEnd = [];

    private PlannerPreferences $preferences;

    private float $dayWeight;

    private float $gapHourWeight;

    private int $nodes = 0;

    /**
     * Generate the ranked combinations for the student from the classes scheduled in the academic period.
     */
    public function handle(User $student, AcademicPeriod $period, PlannerPreferences $preferences): PlannerResult
    {
        $this->reset($preferences);

        $warnings = [];
        $currentClassIds = $student->classesInPeriod($period)->pluck('course_classes.id')->map(fn (mixed $id): int => (int) $id)->all();

        $classes = CourseClass::query()
            ->inPeriod($period)
            ->with(['course', 'lecturers'])
            ->withCount('students')
            ->get();

        if ($classes->isEmpty()) {
            return new PlannerResult([], ["Belum ada data kelas untuk periode {$period->label()}. Minta admin mengimpor jadwal terlebih dahulu."], 0, false);
        }

        $lockedClasses = $preferences->keepCurrentClasses
            ? $classes->filter(fn (CourseClass $class): bool => in_array($class->id, $currentClassIds, true))
            : new Collection;
        $lockedCourseIds = $lockedClasses->map(fn (CourseClass $class): int => $class->course_id)->values()->all();

        $candidates = $classes
            ->reject(fn (CourseClass $class): bool => in_array($class->course_id, $lockedCourseIds, true))
            ->filter(fn (CourseClass $class): bool => $this->passesFilters($class, $currentClassIds))
            ->concat($lockedClasses)
            ->groupBy('course_id');

        $courses = $classes->map(fn (CourseClass $class): Course => $class->course)->unique('id')->keyBy('id');

        $requiredCourseIds = $this->requiredCourseIds($courses, $lockedCourseIds);

        foreach ($requiredCourseIds as $courseId) {
            if (! $candidates->has($courseId)) {
                $warnings[] = "Mata kuliah {$courses[$courseId]->name} wajib diambil, tetapi tidak ada kelasnya yang memenuhi filter.";
            }
        }

        $requiredSks = collect($requiredCourseIds)->sum(fn (int $courseId): int => $courses[$courseId]->sks);

        if ($requiredSks > $preferences->maxSks) {
            $warnings[] = "Total SKS mata kuliah wajib ({$requiredSks}) melebihi batas {$preferences->maxSks} SKS.";
        }

        $optionalCourseIds = $courses
            ->filter(fn (Course $course): bool => ! in_array($course->id, $requiredCourseIds, true)
                && ! in_array($course->id, $preferences->excludedCourseIds, true)
                && $candidates->has($course->id)
                && $this->isOptionalCourse($course))
            ->keys()
            ->all();

        $availableElectives = collect($requiredCourseIds)->concat($optionalCourseIds)
            ->filter(fn (int $courseId): bool => $courses[$courseId]->type === CourseType::Elective)
            ->count();

        if ($availableElectives < $preferences->minElectives) {
            $warnings[] = "Hanya {$availableElectives} mata kuliah pilihan yang memenuhi filter, kurang dari minimal {$preferences->minElectives}.";
        }

        if ($warnings !== []) {
            return new PlannerResult([], $warnings, 0, false);
        }

        $this->buildSearchSpace($courses, $candidates, $requiredCourseIds, $optionalCourseIds);
        $this->search(0, 0, 0, 0, 0, 0, 0);

        $combinations = $this->combinations($classes->keyBy('id'));
        $truncated = $this->nodes >= self::NODE_BUDGET;

        if ($combinations === []) {
            $warnings[] = 'Tidak ada kombinasi tanpa bentrok yang memenuhi semua syarat. Coba longgarkan filter atau kurangi mata kuliah yang diwajibkan.';
        }

        if ($truncated) {
            $warnings[] = 'Pencarian dihentikan lebih awal karena terlalu banyak kemungkinan; hasil terbaik yang ditemukan tetap ditampilkan.';
        }

        return new PlannerResult($combinations, $warnings, $this->nodes, $truncated);
    }

    /**
     * Reset the search state so the action can be reused.
     */
    private function reset(PlannerPreferences $preferences): void
    {
        $this->preferences = $preferences;
        $this->dayWeight = $preferences->compactDays ? self::COMPACT_DAY_WEIGHT : self::DEFAULT_DAY_WEIGHT;
        $this->gapHourWeight = $preferences->minimizeGaps ? self::GAP_HOUR_WEIGHT : self::DEFAULT_GAP_HOUR_WEIGHT;
        $this->pool = [];
        $this->courses = [];
        $this->best = [];
        $this->chosen = [];
        $this->courseTaken = [];
        $this->dayEnd = [];
        $this->nodes = 0;
    }

    /**
     * Determine whether a class satisfies the hard filters.
     *
     * @param  array<int, int>  $currentClassIds
     */
    private function passesFilters(CourseClass $class, array $currentClassIds): bool
    {
        $preferences = $this->preferences;

        if (in_array($class->day->value, $preferences->avoidedDays, true)) {
            return false;
        }

        if ($preferences->earliestStart !== null && $class->start_time < $preferences->earliestStart) {
            return false;
        }

        if ($preferences->latestEnd !== null && $class->end_time > $preferences->latestEnd) {
            return false;
        }

        if ($preferences->language !== null && $this->normalizeLanguage($class->language) !== $this->normalizeLanguage($preferences->language)) {
            return false;
        }

        if ($preferences->prodi !== [] && $class->prodiList() !== []) {
            $wanted = array_map(fn (string $prodi): string => Str::upper(trim($prodi)), $preferences->prodi);
            $offered = array_map(fn (string $prodi): string => Str::upper($prodi), $class->prodiList());

            if (array_intersect($wanted, $offered) === []) {
                return false;
            }
        }

        if ($class->lecturers->pluck('id')->intersect($preferences->avoidedLecturerIds)->isNotEmpty()) {
            return false;
        }

        if ($preferences->skipFullClasses && $class->capacity !== null) {
            $takenByOthers = (int) $class->students_count - (in_array($class->id, $currentClassIds, true) ? 1 : 0);

            if ($takenByOthers >= $class->capacity) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalise a language label so "English" and "Inggris" compare equal.
     */
    private function normalizeLanguage(string $language): string
    {
        $language = Str::lower(trim($language));

        return in_array($language, ['english', 'inggris', 'bahasa inggris', 'en'], true) ? 'english' : $language;
    }

    /**
     * Determine the courses every combination must contain.
     *
     * @param  BaseCollection<int, Course>  $courses
     * @param  array<int, int>  $lockedCourseIds
     * @return list<int>
     */
    private function requiredCourseIds(BaseCollection $courses, array $lockedCourseIds): array
    {
        $preferences = $this->preferences;

        $semesterMandatory = $preferences->semester === null ? [] : $courses
            ->filter(fn (Course $course): bool => $course->type === CourseType::Mandatory
                && $course->semester === $preferences->semester
                && ! in_array($course->id, $preferences->excludedCourseIds, true))
            ->keys()
            ->all();

        $explicit = array_filter($preferences->requiredCourseIds, fn (int $courseId): bool => $courses->has($courseId));

        return array_values(array_unique([...$lockedCourseIds, ...$semesterMandatory, ...$explicit]));
    }

    /**
     * Determine whether a non-required course may be added to a combination.
     */
    private function isOptionalCourse(Course $course): bool
    {
        $preferences = $this->preferences;

        if (in_array($course->id, $preferences->priorityCourseIds, true)) {
            return true;
        }

        return match ($course->type) {
            CourseType::Elective => true,
            CourseType::General => $preferences->includeGeneral,
            CourseType::Mandatory => $preferences->includeOtherMandatory && $course->semester !== $preferences->semester,
        };
    }

    /**
     * Flatten the courses and their candidate classes into compact arrays, with classes in chronological order.
     *
     * @param  BaseCollection<int, Course>  $courses
     * @param  BaseCollection<int, Collection<int, CourseClass>>  $candidates
     * @param  list<int>  $requiredCourseIds
     * @param  array<int, int>  $optionalCourseIds
     */
    private function buildSearchSpace(BaseCollection $courses, BaseCollection $candidates, array $requiredCourseIds, array $optionalCourseIds): void
    {
        $preferences = $this->preferences;
        $entries = [];
        $pool = [];

        foreach ([...$requiredCourseIds, ...$optionalCourseIds] as $courseIndex => $courseId) {
            $course = $courses[$courseId];
            $isPriority = in_array($courseId, $preferences->priorityCourseIds, true);

            $entries[$courseIndex] = [
                'id' => $courseId,
                'sks' => $course->sks,
                'elective' => $course->type === CourseType::Elective,
                'priority' => $isPriority,
                'required' => in_array($courseId, $requiredCourseIds, true),
            ];

            foreach ($candidates[$courseId] as $class) {
                $pool[] = [
                    'id' => $class->id,
                    'course' => $courseIndex,
                    'day' => $class->day->value,
                    'start' => $this->minutes($class->start_time),
                    'end' => $this->minutes($class->end_time),
                    'preferred' => $class->lecturers->pluck('id')->intersect($preferences->preferredLecturerIds)->isNotEmpty(),
                    'priority' => $isPriority,
                ];
            }
        }

        // Chronological order; within the same slot try priority courses and preferred lecturers first so strong
        // combinations are found early and tighten the bound sooner.
        usort($pool, fn (array $a, array $b): int => ($a['day'] <=> $b['day'])
            ?: ($a['start'] <=> $b['start'])
            ?: ($b['priority'] <=> $a['priority'])
            ?: ($b['preferred'] <=> $a['preferred'])
            ?: ($a['end'] <=> $b['end'])
            ?: ($a['id'] <=> $b['id']));

        $lastPosition = [];
        $lastPreferredPosition = [];

        foreach ($pool as $position => $class) {
            $lastPosition[$class['course']] = $position;

            if ($class['preferred']) {
                $lastPreferredPosition[$class['course']] = $position;
            }
        }

        $this->pool = $pool;
        $this->courses = [];

        foreach ($entries as $courseIndex => $entry) {
            $this->courses[] = [
                ...$entry,
                'lastPosition' => $lastPosition[$courseIndex] ?? -1,
                'lastPreferredPosition' => $lastPreferredPosition[$courseIndex] ?? -1,
            ];
            $this->courseTaken[$courseIndex] = false;
        }
    }

    /**
     * Decide whether to take the class at $position, then continue with the next class.
     */
    private function search(int $position, int $sks, int $electives, int $priorities, int $preferred, int $days, int $gaps): void
    {
        if (++$this->nodes >= self::NODE_BUDGET) {
            return;
        }

        $preferences = $this->preferences;
        $remainingSks = 0;
        $remainingElectives = 0;
        $remainingPriorities = 0;
        $remainingPreferred = 0;

        foreach ($this->courses as $courseIndex => $course) {
            if ($this->courseTaken[$courseIndex]) {
                continue;
            }

            if ($course['lastPosition'] < $position) {
                if ($course['required']) {
                    return;
                }

                continue;
            }

            $remainingSks += $course['sks'];
            $remainingElectives += $course['elective'] ? 1 : 0;
            $remainingPriorities += $course['priority'] ? 1 : 0;
            $remainingPreferred += $course['lastPreferredPosition'] >= $position ? 1 : 0;
        }

        if ($electives + $remainingElectives < $preferences->minElectives || $sks + $remainingSks < $preferences->minSks) {
            return;
        }

        $upperBound = ($sks + min($remainingSks, $preferences->maxSks - $sks)) * self::SKS_WEIGHT
            + ($priorities + $remainingPriorities) * self::PRIORITY_WEIGHT
            + ($preferred + $remainingPreferred) * self::PREFERRED_LECTURER_WEIGHT
            - $days * $this->dayWeight
            - ($gaps / 60) * $this->gapHourWeight;

        if ($upperBound <= $this->cutoffScore()) {
            return;
        }

        if ($position === count($this->pool)) {
            $this->record($sks, $electives, $priorities, $preferred, $days, $gaps);

            return;
        }

        $class = $this->pool[$position];
        $course = $this->courses[$class['course']];
        $previousEnd = $this->dayEnd[$class['day']] ?? null;

        $canTake = ! $this->courseTaken[$class['course']]
            && $sks + $course['sks'] <= $preferences->maxSks
            && ($previousEnd === null || $class['start'] >= $previousEnd)
            && ! ($course['elective'] && ! $course['required'] && $preferences->maxElectives !== null && $electives >= $preferences->maxElectives);

        if ($canTake) {
            $gap = $previousEnd === null ? 0 : $class['start'] - $previousEnd;

            $this->chosen[] = $position;
            $this->courseTaken[$class['course']] = true;
            $this->dayEnd[$class['day']] = $class['end'];

            $this->search(
                $position + 1,
                $sks + $course['sks'],
                $electives + ($course['elective'] ? 1 : 0),
                $priorities + ($course['priority'] ? 1 : 0),
                $preferred + ($class['preferred'] ? 1 : 0),
                $days + ($previousEnd === null ? 1 : 0),
                $gaps + ($gap > self::IGNORED_GAP_MINUTES ? $gap : 0),
            );

            array_pop($this->chosen);
            $this->courseTaken[$class['course']] = false;

            if ($previousEnd === null) {
                unset($this->dayEnd[$class['day']]);
            } else {
                $this->dayEnd[$class['day']] = $previousEnd;
            }
        }

        $this->search($position + 1, $sks, $electives, $priorities, $preferred, $days, $gaps);
    }

    /**
     * Score a new combination must beat to enter the result list.
     */
    private function cutoffScore(): float
    {
        $scores = array_column($this->best, 'score');

        if ($scores === [] || count($scores) < $this->preferences->limit) {
            return -INF;
        }

        return min($scores);
    }

    /**
     * Score the current selection and keep it if it ranks among the best course sets.
     */
    private function record(int $sks, int $electives, int $priorities, int $preferred, int $days, int $gaps): void
    {
        if ($this->chosen === []) {
            return;
        }

        $score = $sks * self::SKS_WEIGHT
            + $priorities * self::PRIORITY_WEIGHT
            + $preferred * self::PREFERRED_LECTURER_WEIGHT
            - $days * $this->dayWeight
            - ($gaps / 60) * $this->gapHourWeight;

        $courseIds = array_map(fn (int $position): int => $this->courses[$this->pool[$position]['course']]['id'], $this->chosen);
        sort($courseIds);
        $key = implode('-', $courseIds);

        if (isset($this->best[$key]) && $this->best[$key]['score'] >= $score) {
            return;
        }

        if (! isset($this->best[$key]) && count($this->best) >= $this->preferences->limit) {
            $scores = array_column($this->best, 'score', 'key');
            $worstKey = $scores === [] ? false : array_search(min($scores), $scores, true);

            if ($worstKey === false || $this->best[$worstKey]['score'] >= $score) {
                return;
            }

            unset($this->best[$worstKey]);
        }

        $this->best[$key] = [
            'key' => $key,
            'score' => $score,
            'classes' => $this->chosen,
            'sks' => $sks,
            'electives' => $electives,
            'priorities' => $priorities,
            'preferred' => $preferred,
            'days' => $days,
            'gaps' => $gaps,
        ];
    }

    /**
     * Turn the recorded best entries into ranked combinations.
     *
     * @param  BaseCollection<int, CourseClass>  $classesById
     * @return list<ScheduleCombination>
     */
    private function combinations(BaseCollection $classesById): array
    {
        $best = array_values($this->best);
        usort($best, fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['key'], $b['key']));

        return array_map(fn (array $entry): ScheduleCombination => new ScheduleCombination(
            classes: array_map(fn (int $position): CourseClass => $classesById[$this->pool[$position]['id']], $entry['classes']),
            score: $entry['score'],
            totalSks: $entry['sks'],
            electiveCount: $entry['electives'],
            priorityCount: $entry['priorities'],
            preferredLecturerCount: $entry['preferred'],
            dayCount: $entry['days'],
            gapMinutes: $entry['gaps'],
        ), $best);
    }

    /**
     * Convert "HH:MM" into minutes after midnight.
     */
    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }
}
