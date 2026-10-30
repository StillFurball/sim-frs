<?php

namespace App\Actions\Krs;

use App\Exceptions\EnrollmentException;
use App\Models\AcademicPeriod;
use App\Models\CourseClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Replace a student's KRS for one academic period with the given set of classes after checking every enrollment rule.
 *
 * Rules: the user is a student, the period is the active one, every class is scheduled in that period, at most one
 * class per course, no overlapping schedules, the total SKS stays within the student's limit and newly taken classes
 * still have a free seat. Enrollments in other periods are never touched.
 */
class SyncStudentClasses
{
    /**
     * Validate the selection and persist it as the student's KRS for the period.
     *
     * @param  list<int>  $classIds
     *
     * @throws EnrollmentException
     */
    public function handle(User $student, AcademicPeriod $period, array $classIds): void
    {
        DB::transaction(function () use ($student, $period, $classIds): void {
            $classIds = array_values(array_unique($classIds));

            $classes = CourseClass::query()
                ->with('course')
                ->withCount('students')
                ->whereKey($classIds)
                ->get();

            if ($classes->count() !== count($classIds)) {
                throw new EnrollmentException('Sebagian kelas yang dipilih sudah tidak tersedia.');
            }

            $this->validate($student, $period, $classes);

            $currentClassIds = $this->currentClassIds($student, $period);
            $selectedClassIds = $classes->modelKeys();

            $student->courseClasses()->detach(array_values(array_diff($currentClassIds, $selectedClassIds)));
            $student->courseClasses()->attach(array_values(array_diff($selectedClassIds, $currentClassIds)));
        });
    }

    /**
     * Check every enrollment rule against the full selection for the period.
     *
     * @param  Collection<int, CourseClass>  $classes
     *
     * @throws EnrollmentException
     */
    public function validate(User $student, AcademicPeriod $period, Collection $classes): void
    {
        if (! $student->isStudent()) {
            throw new EnrollmentException('Hanya mahasiswa yang dapat mengisi KRS.');
        }

        if (! $period->is_active) {
            throw new EnrollmentException("Pengisian KRS periode {$period->label()} sedang tidak dibuka.");
        }

        $outsidePeriod = $classes->first(fn (CourseClass $class): bool => $class->academic_period_id !== $period->id);

        if ($outsidePeriod !== null) {
            throw new EnrollmentException("Kelas {$outsidePeriod->course->name} ({$outsidePeriod->label()}) tidak dijadwalkan pada periode {$period->label()}.");
        }

        $duplicateCourse = $classes->groupBy('course_id')->first(fn (Collection $group): bool => $group->count() > 1);

        if ($duplicateCourse !== null) {
            throw new EnrollmentException("Mata kuliah {$duplicateCourse->first()?->course->name} hanya boleh diambil di satu kelas.");
        }

        $totalSks = $classes->sum(fn (CourseClass $class): int => $class->course->sks);

        if ($totalSks > $student->max_sks) {
            throw new EnrollmentException("Total SKS ({$totalSks}) melebihi batas SKS kamu ({$student->max_sks}).");
        }

        $values = $classes->values();

        foreach ($values as $index => $class) {
            foreach ($values->slice($index + 1) as $other) {
                if ($class->overlaps($other)) {
                    throw new EnrollmentException(
                        "Jadwal {$class->course->name} ({$class->label()}, {$class->scheduleLabel()}) bentrok dengan {$other->course->name} ({$other->label()}, {$other->scheduleLabel()}).",
                    );
                }
            }
        }

        $currentClassIds = $this->currentClassIds($student, $period);

        foreach ($classes as $class) {
            if (! in_array($class->id, $currentClassIds, true) && $class->isFull()) {
                throw new EnrollmentException("Kelas {$class->course->name} ({$class->label()}) sudah penuh.");
            }
        }
    }

    /**
     * Get the IDs of the classes the student currently holds in the period.
     *
     * @return list<int>
     */
    private function currentClassIds(User $student, AcademicPeriod $period): array
    {
        return array_values(array_map(intval(...), $student->classesInPeriod($period)->pluck('course_classes.id')->all()));
    }
}
