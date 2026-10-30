<?php

namespace App\Models;

use App\Enums\CourseType;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $code
 * @property string $name
 * @property int|null $semester
 * @property CourseType $type
 * @property int $sks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'semester', 'type', 'sks'])]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CourseType::class,
            'semester' => 'integer',
            'sks' => 'integer',
        ];
    }

    /**
     * Get the classes (parallel sections) offered for the course.
     *
     * @return HasMany<CourseClass, $this>
     */
    public function classes(): HasMany
    {
        return $this->hasMany(CourseClass::class);
    }
}
