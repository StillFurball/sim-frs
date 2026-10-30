<?php

namespace App\Http\Controllers;

use App\Enums\CourseType;
use App\Enums\Day;
use App\Models\AcademicPeriod;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\Lecturer;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class WelcomeController extends Controller
{
    /**
     * The number of courses offered on the landing page's trial card.
     */
    private const int TRIAL_COURSE_LIMIT = 8;

    /**
     * The number of classes filled in on the trial card before the visitor touches it.
     */
    private const int PRESELECTED_CLASS_LIMIT = 4;

    /**
     * Show the landing page with a trial KRS card built from the active period's schedule.
     */
    public function __invoke(): View
    {
        $period = AcademicPeriod::current();
        $courses = $period ? $this->trialCourses($period) : new Collection;

        return view('welcome', [
            'period' => $period,
            'stats' => $this->stats($period),
            'trialCourses' => $courses->map(fn (Course $course): array => $this->courseCard($course))->values()->all(),
            'preselectedClassIds' => $this->preselect($courses),
            'days' => array_filter(Day::cases(), fn (Day $day): bool => $day !== Day::Sabtu),
            'startTimes' => $courses->flatMap->classes->pluck('start_time')->unique()->sort()->values()->all(),
            'maxSks' => max(User::SKS_LIMITS),
            'sksLimits' => User::SKS_LIMITS,
        ]);
    }

    /**
     * Get the non-general courses with the most parallel classes in the period, with those classes loaded.
     *
     * @return Collection<int, Course>
     */
    private function trialCourses(AcademicPeriod $period): Collection
    {
        $inPeriod = fn (Builder $query): Builder => $query->where('academic_period_id', $period->id);

        return Course::query()
            ->where('type', '!=', CourseType::General)
            ->whereHas('classes', $inPeriod)
            ->withCount(['classes' => $inPeriod])
            ->with(['classes' => fn ($query) => $query->inPeriod($period)->where('day', '!=', Day::Sabtu)->orderBy('day')->orderBy('start_time')->with('lecturers')])
            ->orderByDesc('classes_count')
            ->orderBy('name')
            ->limit(self::TRIAL_COURSE_LIMIT)
            ->get();
    }

    /**
     * Count what the active period offers.
     *
     * @return array{courses: int, classes: int, lecturers: int}
     */
    private function stats(?AcademicPeriod $period): array
    {
        if ($period === null) {
            return ['courses' => 0, 'classes' => 0, 'lecturers' => 0];
        }

        return [
            'courses' => Course::query()->whereHas('classes', fn (Builder $query) => $query->where('academic_period_id', $period->id))->count(),
            'classes' => $period->classes()->count(),
            'lecturers' => Lecturer::query()->whereHas('classes', fn (Builder $query) => $query->where('academic_period_id', $period->id))->count(),
        ];
    }

    /**
     * Shape a course and its classes for the trial card.
     *
     * @return array{id: int, name: string, short: string, sks: int, type: string, classes: list<array{id: int, label: string, day: int, start: string, end: string, room: string|null, capacity: int|null, lecturers: string}>}
     */
    private function courseCard(Course $course): array
    {
        return [
            'id' => $course->id,
            'name' => $course->name,
            'short' => $this->abbreviate($course->name),
            'sks' => $course->sks,
            'type' => $course->type->label(),
            'classes' => array_values($course->classes->map(fn (CourseClass $class): array => [
                'id' => $class->id,
                'label' => $class->label(),
                'day' => $class->day->value,
                'start' => $class->start_time,
                'end' => $class->end_time,
                'room' => $class->room,
                'capacity' => $class->capacity,
                'lecturers' => $class->lecturerCodes(),
            ])->all()),
        ];
    }

    /**
     * Pick the first class of each course that fits beside the ones already picked, so the card never opens empty.
     *
     * @param  Collection<int, Course>  $courses
     * @return list<int>
     */
    private function preselect(Collection $courses): array
    {
        /** @var list<CourseClass> $picked */
        $picked = [];

        foreach ($courses as $course) {
            if (count($picked) >= self::PRESELECTED_CLASS_LIMIT) {
                break;
            }

            $fitting = $course->classes->first(
                fn (CourseClass $class): bool => collect($picked)->doesntContain(fn (CourseClass $other): bool => $class->overlaps($other))
            );

            if ($fitting !== null) {
                $picked[] = $fitting;
            }
        }

        return array_map(fn (CourseClass $class): int => $class->id, $picked);
    }

    /**
     * Abbreviate a course name the way students say it, e.g. "Interaksi Manusia dan Komputer" becomes "IMK".
     */
    private function abbreviate(string $name): string
    {
        $words = array_filter(explode(' ', $name), fn (string $word): bool => ! in_array(Str::lower($word), ['dan', 'dalam', 'untuk'], true));

        if (count($words) < 2) {
            return Str::upper(Str::substr($name, 0, 3));
        }

        return Str::upper(implode('', array_map(fn (string $word): string => Str::substr($word, 0, 1), $words)));
    }
}
