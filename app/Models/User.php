<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property string|null $nrp
 * @property int $max_sks
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'nrp', 'max_sks'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The SKS limits a student may be granted, from the highest to the lowest.
     *
     * @var list<int>
     */
    public const array SKS_LIMITS = [24, 22, 20, 18];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'student',
        'max_sks' => 24,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'max_sks' => 'integer',
        ];
    }

    /**
     * Get the classes the student has taken in their KRS, across every academic period.
     *
     * @return BelongsToMany<CourseClass, $this>
     */
    public function courseClasses(): BelongsToMany
    {
        return $this->belongsToMany(CourseClass::class, 'enrollments')->withTimestamps();
    }

    /**
     * Get the classes in the student's KRS for one academic period.
     *
     * @return BelongsToMany<CourseClass, $this>
     */
    public function classesInPeriod(AcademicPeriod|int $period): BelongsToMany
    {
        return $this->courseClasses()->where('course_classes.academic_period_id', $period instanceof AcademicPeriod ? $period->id : $period);
    }

    /**
     * Determine whether the user is an administrator.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Determine whether the user is a student.
     */
    public function isStudent(): bool
    {
        return $this->role === UserRole::Student;
    }

    /**
     * Get the total SKS the student has taken in an academic period.
     */
    public function takenSks(AcademicPeriod|int $period): int
    {
        return (int) $this->classesInPeriod($period)
            ->join('courses', 'courses.id', '=', 'course_classes.course_id')
            ->sum('courses.sks');
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
