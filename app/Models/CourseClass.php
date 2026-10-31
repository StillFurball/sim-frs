<?php

namespace App\Models;

use App\Enums\Day;
use Database\Factories\CourseClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A parallel class (section) of a course with its own schedule, room and lecturers.
 *
 * @property int $id
 * @property int $academic_period_id
 * @property int $course_id
 * @property int $sks
 * @property string $name
 * @property string $prodi
 * @property Day $day
 * @property string $start_time
 * @property string $end_time
 * @property string|null $room
 * @property int|null $capacity
 * @property string $language
 * @property string|null $ramadhan_schedule
 * @property int|null $students_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Course $course
 * @property-read AcademicPeriod $academicPeriod
 */
#[Fillable(['academic_period_id', 'course_id', 'sks', 'name', 'prodi', 'day', 'start_time', 'end_time', 'room', 'capacity', 'language', 'ramadhan_schedule'])]
class CourseClass extends Model
{
    /** @use HasFactory<CourseClassFactory> */
    use HasFactory;

    /**
     * Take the SKS from the course catalogue when a class is created or moved to another course without its own SKS.
     */
    protected static function booted(): void
    {
        static::saving(function (CourseClass $class): void {
            if (! $class->isDirty('sks') && (! $class->exists || $class->isDirty('course_id'))) {
                $class->sks = Course::query()->whereKey($class->course_id)->value('sks');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'academic_period_id' => 'integer',
            'course_id' => 'integer',
            'sks' => 'integer',
            'day' => Day::class,
            'capacity' => 'integer',
        ];
    }

    /**
     * Get the academic period the class is scheduled in.
     *
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /**
     * Limit the query to classes scheduled in the given period.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInPeriod(Builder $query, AcademicPeriod|int $period): void
    {
        $query->where('course_classes.academic_period_id', $period instanceof AcademicPeriod ? $period->id : $period);
    }

    /**
     * Get the course the class belongs to.
     *
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the lecturers teaching the class.
     *
     * @return BelongsToMany<Lecturer, $this>
     */
    public function lecturers(): BelongsToMany
    {
        return $this->belongsToMany(Lecturer::class);
    }

    /**
     * Get the students enrolled in the class.
     *
     * @return BelongsToMany<User, $this>
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'enrollments')->withTimestamps();
    }

    /**
     * Determine whether the class schedule overlaps with another class.
     */
    public function overlaps(CourseClass $other): bool
    {
        return $this->day === $other->day
            && $this->start_time < $other->end_time
            && $other->start_time < $this->end_time;
    }

    /**
     * Get the class label shown to users, e.g. "A" or "- (IUP)" for classes split by study program.
     */
    public function label(): string
    {
        if (in_array($this->name, ['', '-'], true) && filled($this->prodi)) {
            return $this->prodi;
        }

        return $this->name;
    }

    /**
     * Get the schedule as a readable string, e.g. "Senin 07:00–08:50".
     */
    public function scheduleLabel(): string
    {
        return "{$this->day->label()} {$this->start_time}–{$this->end_time}";
    }

    /**
     * Get the lecturer codes of the class as a comma separated string.
     */
    public function lecturerCodes(): string
    {
        return $this->lecturers->pluck('code')->implode(', ');
    }

    /**
     * Get the study programs the class is opened for.
     *
     * @return list<string>
     */
    public function prodiList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->prodi))));
    }

    /**
     * Determine whether the class has reached its capacity. Requires the `students_count` aggregate when loaded in bulk.
     */
    public function isFull(): bool
    {
        if ($this->capacity === null) {
            return false;
        }

        $enrolled = $this->students_count ?? $this->students()->count();

        return $enrolled >= $this->capacity;
    }
}
