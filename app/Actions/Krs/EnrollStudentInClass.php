<?php

namespace App\Actions\Krs;

use App\Exceptions\EnrollmentException;
use App\Models\CourseClass;
use App\Models\User;

/**
 * Add a class to a student's KRS for the class's academic period. Taking another class of an already taken course
 * in that period moves the student to it.
 */
class EnrollStudentInClass
{
    public function __construct(private SyncStudentClasses $syncStudentClasses) {}

    /**
     * Enroll the student and return the class they were moved out of, if any.
     *
     * @throws EnrollmentException
     */
    public function handle(User $student, CourseClass $class): ?CourseClass
    {
        $period = $class->academicPeriod;
        $current = $student->classesInPeriod($period)->get();

        if ($current->contains($class)) {
            throw new EnrollmentException("Kamu sudah mengambil {$class->course->name} kelas {$class->label()}.");
        }

        $replaced = $current->firstWhere('course_id', $class->course_id);

        $classIds = $current
            ->reject(fn (CourseClass $taken): bool => $taken->course_id === $class->course_id)
            ->modelKeys();

        $this->syncStudentClasses->handle($student, $period, [...$classIds, $class->id]);

        return $replaced;
    }
}
