<?php

namespace App\Models;

use Database\Factories\LecturerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string|null $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name'])]
class Lecturer extends Model
{
    /** @use HasFactory<LecturerFactory> */
    use HasFactory;

    /**
     * Get the classes taught by the lecturer.
     *
     * @return BelongsToMany<CourseClass, $this>
     */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(CourseClass::class);
    }

    /**
     * Resolve a list of lecturer codes such as "BN, MA" or "AB / CD" to lecturer IDs, creating unknown codes.
     *
     * @return list<int>
     */
    public static function idsFromCodes(?string $codes): array
    {
        $codes = array_unique(array_filter(array_map('trim', preg_split('/\s*[,;\/&]\s*/', (string) $codes) ?: [])));

        return array_values(array_map(
            fn (string $code): int => static::query()->firstOrCreate(['code' => $code])->id,
            $codes,
        ));
    }

    /**
     * Get the display name, falling back to the lecturer code.
     */
    public function displayName(): string
    {
        return filled($this->name) ? "{$this->name} ({$this->code})" : $this->code;
    }
}
