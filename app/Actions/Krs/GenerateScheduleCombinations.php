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
 * are added on top. Remedial classes ("kelas R") are left out unless the student explicitly requires or prioritises
 * their course, so retake-only courses never inflate the semester's mandatory load.
 *
 * The search is a depth-first branch-and-bound over courses: required courses first (fewest classes first), then the
 * optional ones, picking one class (or, for optional courses, none) per course. Because the optional courses are
 * decided last, backtracking varies the set of courses before it revisits the classes of the required ones, so
 * distinct study plans are found right away instead of after every arrangement of parallel classes. The upper bound
 * uses the exact SKS total still reachable within the limit (a subset sum over the remaining courses), which prunes
 * branches that cannot fill the limit any better. The results prefer distinct sets of courses; when there are fewer
 * of those than requested, alternative arrangements of the same courses (other parallel classes) fill the list.
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
     * Candidate classes, grouped per course in the order the search tries them.
     *
     * @var list<array{id: int, course: int, sks: int, day: int, start: int, end: int, preferred: bool}>
     */
    private array $pool = [];

    /**
     * Courses in search order (required first), each with the pool positions of its candidate classes.
     *
     * @var list<array{id: int, elective: bool, priority: bool, required: bool, classes: list<int>}>
     */
    private array $courses = [];

    /**
     * For each course index, a bitmask of the SKS totals the courses from that index on can still add (bit n = n SKS).
     *
     * @var array<int, int>
     */
    private array $suffixReachableSks = [];

    /**
     * For each course index, how many of the courses from that index on are electives.
     *
     * @var array<int, int>
     */
    private array $suffixElectives = [];

    /**
     * For each course index, how many of the courses from that index on are priority courses.
     *
     * @var array<int, int>
     */
    private array $suffixPriorities = [];

    /**
     * For each course index, how many of the courses from that index on have a class with a preferred lecturer.
     *
     * @var array<int, int>
     */
    private array $suffixPreferred = [];

    /**
     * For each course index, how many of the courses from that index on are required.
     *
     * @var array<int, int>
     */
    private array $suffixRequired = [];

    /**
     * Best arrangements (sets of parallel classes) per course set, best first, keyed by the sorted course IDs.
     *
     * @var array<string, list<array{key: string, classKey: string, score: float, classes: list<int>, sks: int, electives: int, priorities: int, preferred: int, days: int, gaps: int}>>
     */
    private array $best = [];

    /**
     * Score a combination must beat to still make the result list; see raiseCutoff().
     */
    private float $cutoff = -INF;

    /**
     * Pool positions of the classes currently chosen.
     *
     * @var list<int>
     */
    private array $chosen = [];

    /**
     * Start and end (in minutes) of the chosen classes, per day.
     *
     * @var array<int, list<array{0: int, 1: int}>>
     */
    private array $dayIntervals = [];

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
            ->reject(fn (CourseClass $class): bool => $class->isRemedial() && ! $this->isChosenByStudent($class->course_id))
            ->filter(fn (CourseClass $class): bool => $this->passesFilters($class, $currentClassIds))
            ->concat($lockedClasses)
            ->groupBy('course_id');

        $courses = $classes->map(fn (CourseClass $class): Course => $class->course)->unique('id')->keyBy('id');
        $regularCourseIds = $classes
            ->reject(fn (CourseClass $class): bool => $class->isRemedial())
            ->filter(fn (CourseClass $class): bool => $this->isOpenForPreferredProdi($class))
            ->map(fn (CourseClass $class): int => (int) $class->course_id)
            ->unique()
            ->values()
            ->all();

        $requiredCourseIds = $this->requiredCourseIds($courses, $regularCourseIds, $lockedCourseIds);

        foreach ($requiredCourseIds as $courseId) {
            if (! $candidates->has($courseId)) {
                $warnings[] = "Mata kuliah {$courses[$courseId]->name} wajib diambil, tetapi tidak ada kelasnya yang memenuhi filter.";
            }
        }

        $requiredSks = collect($requiredCourseIds)
            ->sum(fn (int $courseId): int => (int) ($candidates[$courseId] ?? $classes->where('course_id', $courseId))->min('sks'));

        if ($requiredSks > $preferences->maxSks) {
            $warnings[] = "Total SKS mata kuliah wajib ({$requiredSks}) melebihi batas {$preferences->maxSks} SKS."
                .(count($preferences->prodi) !== 1 ? ' Pilih prodi kamu saja di filter Prodi agar MK wajib prodi lain tidak ikut diwajibkan.' : '');
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

        if ($warnings === []) {
            $requiredElectives = collect($requiredCourseIds)->filter(fn (int $courseId): bool => $courses[$courseId]->type === CourseType::Elective)->count();
            $electivesNeeded = max(0, $preferences->minElectives - $requiredElectives);
            $lightestElectivesSks = collect($optionalCourseIds)
                ->filter(fn (int $courseId): bool => $courses[$courseId]->type === CourseType::Elective)
                ->map(fn (int $courseId): int => (int) $candidates[$courseId]->min('sks'))
                ->sort()
                ->take($electivesNeeded)
                ->sum();

            if ($electivesNeeded > 0 && $requiredSks + $lightestElectivesSks > $preferences->maxSks) {
                $warnings[] = "Batas {$preferences->maxSks} SKS tidak cukup: MK wajib {$requiredSks} SKS ditambah minimal {$preferences->minElectives} MK pilihan"
                    ." (paling ringan {$lightestElectivesSks} SKS) menjadi ".($requiredSks + $lightestElectivesSks).' SKS.'
                    .' Kurangi "Minimal MK pilihan" atau lepas MK yang diwajibkan.';
            }
        }

        if ($warnings !== []) {
            return new PlannerResult([], $warnings, 0, false);
        }

        $this->buildSearchSpace($courses, $candidates, $requiredCourseIds, $optionalCourseIds);
        $this->search(0, 0, 0, 0, 0, 0);

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
        $this->suffixReachableSks = [];
        $this->suffixElectives = [];
        $this->suffixPriorities = [];
        $this->suffixPreferred = [];
        $this->suffixRequired = [];
        $this->best = [];
        $this->cutoff = -INF;
        $this->chosen = [];
        $this->dayIntervals = [];
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

        if (! $this->isOpenForPreferredProdi($class)) {
            return false;
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
     * Determine whether a class is opened for one of the preferred study programs. Classes without a prodi, or a
     * planner run without a prodi preference, match everything.
     */
    private function isOpenForPreferredProdi(CourseClass $class): bool
    {
        if ($this->preferences->prodi === [] || $class->prodiList() === []) {
            return true;
        }

        $wanted = array_map(fn (string $prodi): string => Str::upper(trim($prodi)), $this->preferences->prodi);
        $offered = array_map(fn (string $prodi): string => Str::upper($prodi), $class->prodiList());

        return array_intersect($wanted, $offered) !== [];
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
     * Determine whether the student explicitly asked for a course ("Wajib ambil" or "Prioritaskan"). Only then may
     * its remedial classes be used: they are meant for students retaking the course, not for the regular load.
     */
    private function isChosenByStudent(int $courseId): bool
    {
        return in_array($courseId, $this->preferences->requiredCourseIds, true)
            || in_array($courseId, $this->preferences->priorityCourseIds, true);
    }

    /**
     * Determine the courses every combination must contain.
     *
     * A mandatory course of the chosen semester is only required when it has a regular (non-remedial) class opened for
     * the preferred study programs: a course offered just as a remedial "kelas R" is a retake for students of later
     * semesters, and another program's mandatory course is not part of this student's semester load.
     *
     * @param  BaseCollection<int, Course>  $courses
     * @param  array<int, int>  $regularCourseIds  Courses with a non-remedial class for the preferred prodi in the period.
     * @param  array<int, int>  $lockedCourseIds
     * @return list<int>
     */
    private function requiredCourseIds(BaseCollection $courses, array $regularCourseIds, array $lockedCourseIds): array
    {
        $preferences = $this->preferences;

        $semesterMandatory = $preferences->semester === null ? [] : $courses
            ->filter(fn (Course $course): bool => $course->type === CourseType::Mandatory
                && $course->semester === $preferences->semester
                && in_array($course->id, $regularCourseIds, true)
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
     * Flatten the courses and their candidate classes into compact arrays in search order, and precompute what the
     * remaining courses can still contribute from every position (used by the upper bound).
     *
     * Required courses come first, those with the fewest classes first, so dead ends surface early. Optional courses
     * follow, priority courses and courses with a preferred lecturer first, then the heavier ones. Within a course,
     * classes with a preferred lecturer are tried first, then in weekly order.
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

        foreach ([...$requiredCourseIds, ...$optionalCourseIds] as $courseId) {
            $classes = $candidates[$courseId]
                ->map(fn (CourseClass $class): array => [
                    'id' => $class->id,
                    'sks' => $class->sks,
                    'day' => $class->day->value,
                    'start' => $this->minutes($class->start_time),
                    'end' => $this->minutes($class->end_time),
                    'preferred' => $class->lecturers->pluck('id')->intersect($preferences->preferredLecturerIds)->isNotEmpty(),
                ])
                ->sort(fn (array $a, array $b): int => ($b['preferred'] <=> $a['preferred'])
                    ?: ($a['day'] <=> $b['day'])
                    ?: ($a['start'] <=> $b['start'])
                    ?: ($a['id'] <=> $b['id']))
                ->values()
                ->all();

            $entries[] = [
                'id' => $courseId,
                'elective' => $courses[$courseId]->type === CourseType::Elective,
                'priority' => in_array($courseId, $preferences->priorityCourseIds, true),
                'required' => in_array($courseId, $requiredCourseIds, true),
                'hasPreferred' => in_array(true, array_column($classes, 'preferred'), true),
                'maxSks' => max([0, ...array_column($classes, 'sks')]),
                'classes' => $classes,
            ];
        }

        usort($entries, fn (array $a, array $b): int => ($b['required'] <=> $a['required'])
            ?: ($a['required'] ? count($a['classes']) <=> count($b['classes']) : 0)
            ?: ($b['priority'] <=> $a['priority'])
            ?: ($b['hasPreferred'] <=> $a['hasPreferred'])
            ?: ($b['maxSks'] <=> $a['maxSks'])
            ?: ($a['id'] <=> $b['id']));

        foreach ($entries as $courseIndex => $entry) {
            $positions = [];

            foreach ($entry['classes'] as $class) {
                $positions[] = count($this->pool);
                $this->pool[] = ['course' => $courseIndex, ...$class];
            }

            $this->courses[] = [
                'id' => $entry['id'],
                'elective' => $entry['elective'],
                'priority' => $entry['priority'],
                'required' => $entry['required'],
                'classes' => $positions,
            ];
        }

        $this->buildSuffixBounds($entries);
    }

    /**
     * Precompute, for every course index, what the courses from that index on can still add: the reachable SKS totals
     * (required courses must be added, optional ones may be) and how many electives, priority courses and preferred
     * lecturer courses remain. Clashes are ignored, so these are optimistic bounds.
     *
     * @param  list<array{elective: bool, priority: bool, required: bool, hasPreferred: bool, classes: array<int, array{sks: int}>}>  $entries
     */
    private function buildSuffixBounds(array $entries): void
    {
        $limitMask = (1 << (min($this->preferences->maxSks, 62) + 1)) - 1;
        $count = count($entries);

        $this->suffixReachableSks = array_fill(0, $count + 1, 1);
        $this->suffixElectives = array_fill(0, $count + 1, 0);
        $this->suffixPriorities = array_fill(0, $count + 1, 0);
        $this->suffixPreferred = array_fill(0, $count + 1, 0);
        $this->suffixRequired = array_fill(0, $count + 1, 0);

        for ($index = $count - 1; $index >= 0; $index--) {
            $entry = $entries[$index];
            $next = $this->suffixReachableSks[$index + 1];
            $withCourse = 0;

            foreach (array_unique(array_column($entry['classes'], 'sks')) as $sks) {
                $withCourse |= $next << $sks;
            }

            $this->suffixReachableSks[$index] = ($entry['required'] ? $withCourse : $next | $withCourse) & $limitMask;
            $this->suffixElectives[$index] = $this->suffixElectives[$index + 1] + ($entry['elective'] ? 1 : 0);
            $this->suffixPriorities[$index] = $this->suffixPriorities[$index + 1] + ($entry['priority'] ? 1 : 0);
            $this->suffixPreferred[$index] = $this->suffixPreferred[$index + 1] + ($entry['hasPreferred'] ? 1 : 0);
            $this->suffixRequired[$index] = $this->suffixRequired[$index + 1] + ($entry['required'] ? 1 : 0);
        }
    }

    /**
     * Decide the class (or, for an optional course, no class) of the course at $index, then continue with the next.
     */
    private function search(int $index, int $sks, int $electives, int $priorities, int $preferred, int $days): void
    {
        if (++$this->nodes >= self::NODE_BUDGET) {
            return;
        }

        $preferences = $this->preferences;
        $capacity = $preferences->maxSks - $sks;
        $reachable = $this->suffixReachableSks[$index] & ((1 << ($capacity + 1)) - 1);

        // The remaining required courses no longer fit within the SKS limit.
        if ($reachable === 0) {
            return;
        }

        $maxAddedSks = strlen(decbin($reachable)) - 1;

        if ($sks + $maxAddedSks < $preferences->minSks || $electives + $this->suffixElectives[$index] < $preferences->minElectives) {
            return;
        }

        // Days on campus only grow as classes are added; idle time may shrink, so it is left out of the bound.
        $upperBound = ($sks + $maxAddedSks) * self::SKS_WEIGHT
            + ($priorities + $this->suffixPriorities[$index]) * self::PRIORITY_WEIGHT
            + ($preferred + $this->suffixPreferred[$index]) * self::PREFERRED_LECTURER_WEIGHT
            - $days * $this->dayWeight;

        if ($upperBound <= $this->cutoffScore()) {
            return;
        }

        // Every course is decided, or nothing optional fits any more and no required course is left.
        if ($index === count($this->courses) || ($reachable === 1 && $this->suffixRequired[$index] === 0)) {
            if ($sks >= $preferences->minSks && $electives >= $preferences->minElectives) {
                $this->record($sks, $electives, $priorities, $preferred, $days);
            }

            return;
        }

        $course = $this->courses[$index];
        $electiveLimitReached = $course['elective'] && ! $course['required']
            && $preferences->maxElectives !== null && $electives >= $preferences->maxElectives;

        if (! $electiveLimitReached) {
            foreach ($course['classes'] as $position) {
                $class = $this->pool[$position];

                if ($class['sks'] > $capacity || $this->clashes($class)) {
                    continue;
                }

                $isNewDay = ! isset($this->dayIntervals[$class['day']]);
                $this->chosen[] = $position;
                $this->dayIntervals[$class['day']][] = [$class['start'], $class['end']];

                $this->search(
                    $index + 1,
                    $sks + $class['sks'],
                    $electives + ($course['elective'] ? 1 : 0),
                    $priorities + ($course['priority'] ? 1 : 0),
                    $preferred + ($class['preferred'] ? 1 : 0),
                    $days + ($isNewDay ? 1 : 0),
                );

                array_pop($this->chosen);
                array_pop($this->dayIntervals[$class['day']]);

                if ($this->dayIntervals[$class['day']] === []) {
                    unset($this->dayIntervals[$class['day']]);
                }
            }
        }

        if (! $course['required']) {
            $this->search($index + 1, $sks, $electives, $priorities, $preferred, $days);
        }
    }

    /**
     * Determine whether a class overlaps a chosen class on the same day (back-to-back classes do not clash).
     *
     * @param  array{day: int, start: int, end: int}  $class
     */
    private function clashes(array $class): bool
    {
        foreach ($this->dayIntervals[$class['day']] ?? [] as [$start, $end]) {
            if ($class['start'] < $end && $start < $class['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Total idle minutes between the chosen classes, ignoring short breaks.
     */
    private function gapMinutes(): int
    {
        $gaps = 0;

        foreach ($this->dayIntervals as $intervals) {
            usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

            for ($i = 1, $count = count($intervals); $i < $count; $i++) {
                $gap = $intervals[$i][0] - $intervals[$i - 1][1];

                if ($gap > self::IGNORED_GAP_MINUTES) {
                    $gaps += $gap;
                }
            }
        }

        return $gaps;
    }

    /**
     * Score a new combination must beat to still be able to enter the result list.
     */
    private function cutoffScore(): float
    {
        return $this->cutoff;
    }

    /**
     * Score the current selection and keep it if it can still make the result list, either as the best arrangement
     * of its course set or as an alternative arrangement (other parallel classes) of a course set already found.
     */
    private function record(int $sks, int $electives, int $priorities, int $preferred, int $days): void
    {
        if ($this->chosen === []) {
            return;
        }

        $gaps = $this->gapMinutes();
        $score = $sks * self::SKS_WEIGHT
            + $priorities * self::PRIORITY_WEIGHT
            + $preferred * self::PREFERRED_LECTURER_WEIGHT
            - $days * $this->dayWeight
            - ($gaps / 60) * $this->gapHourWeight;

        if ($score <= $this->cutoff) {
            return;
        }

        $classes = $this->chosen;
        usort($classes, fn (int $a, int $b): int => ($this->pool[$a]['day'] <=> $this->pool[$b]['day'])
            ?: ($this->pool[$a]['start'] <=> $this->pool[$b]['start']));

        $courseIds = array_map(fn (int $position): int => $this->courses[$this->pool[$position]['course']]['id'], $this->chosen);
        sort($courseIds);
        $key = implode('-', $courseIds);

        $arrangements = $this->best[$key] ?? [];
        $arrangements[] = [
            'key' => $key,
            'classKey' => implode('-', array_map(fn (int $position): int => $this->pool[$position]['id'], $classes)),
            'score' => $score,
            'classes' => $classes,
            'sks' => $sks,
            'electives' => $electives,
            'priorities' => $priorities,
            'preferred' => $preferred,
            'days' => $days,
            'gaps' => $gaps,
        ];
        usort($arrangements, $this->rankEntries(...));
        $this->best[$key] = array_slice($arrangements, 0, $this->preferences->limit);

        $this->raiseCutoff();
    }

    /**
     * Raise the score a combination must beat once the result list is settled up to it, and forget the stored
     * combinations below it.
     *
     * With at least `limit` distinct course sets, only their best arrangements can be shown, so the cutoff is the
     * `limit`-th best of those. With fewer, alternative arrangements fill the list, so it is the `limit`-th best
     * arrangement overall. The cutoff never goes down, which keeps earlier pruning decisions valid.
     */
    private function raiseCutoff(): void
    {
        $limit = $this->preferences->limit;
        $setBestScores = array_map(fn (array $arrangements): float => $arrangements[0]['score'], $this->best);

        if (count($setBestScores) >= $limit) {
            rsort($setBestScores);
            $candidate = $setBestScores[$limit - 1];
        } else {
            $scores = array_merge(...array_map(fn (array $arrangements): array => array_column($arrangements, 'score'), array_values($this->best)));
            rsort($scores);
            $candidate = $scores[$limit - 1] ?? -INF;
        }

        if ($candidate <= $this->cutoff) {
            return;
        }

        $this->cutoff = $candidate;

        foreach ($this->best as $key => $arrangements) {
            $kept = array_values(array_filter($arrangements, fn (array $entry): bool => $entry['score'] >= $candidate));

            if ($kept === []) {
                unset($this->best[$key]);
            } else {
                $this->best[$key] = $kept;
            }
        }
    }

    /**
     * Order stored combinations from best to worst, deterministically.
     *
     * @param  array{key: string, classKey: string, score: float}  $a
     * @param  array{key: string, classKey: string, score: float}  $b
     */
    private function rankEntries(array $a, array $b): int
    {
        return ($b['score'] <=> $a['score']) ?: strcmp($a['key'], $b['key']) ?: strcmp($a['classKey'], $b['classKey']);
    }

    /**
     * Turn the recorded entries into ranked combinations: the best arrangement of each distinct course set first and,
     * when there are fewer course sets than requested, the best alternative arrangements to fill the list.
     *
     * @param  BaseCollection<int, CourseClass>  $classesById
     * @return list<ScheduleCombination>
     */
    private function combinations(BaseCollection $classesById): array
    {
        $limit = $this->preferences->limit;
        $setBests = [];
        $alternatives = [];

        foreach ($this->best as $arrangements) {
            $setBests[] = $arrangements[0];
            array_push($alternatives, ...array_slice($arrangements, 1));
        }

        usort($setBests, $this->rankEntries(...));
        usort($alternatives, $this->rankEntries(...));

        $best = array_slice($setBests, 0, $limit);
        $best = [...$best, ...array_slice($alternatives, 0, $limit - count($best))];
        usort($best, $this->rankEntries(...));

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
