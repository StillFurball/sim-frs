<?php

namespace App\Models;

use App\Enums\Term;
use Database\Factories\AcademicPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An academic period: an academic year (e.g. 2025/2026) and its odd (ganjil) or even (genap) term.
 *
 * Exactly one period is active at a time; students can only change their KRS in the active period.
 *
 * @property int $id
 * @property int $start_year
 * @property Term $term
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['start_year', 'term', 'is_active'])]
class AcademicPeriod extends Model
{
    /** @use HasFactory<AcademicPeriodFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'term' => Term::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the classes scheduled in the period.
     *
     * @return HasMany<CourseClass, $this>
     */
    public function classes(): HasMany
    {
        return $this->hasMany(CourseClass::class);
    }

    /**
     * Get the currently active period, if any.
     */
    public static function current(): ?self
    {
        return static::query()->where('is_active', true)->first();
    }

    /**
     * Order periods from the newest to the oldest.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('start_year')->orderByDesc('term');
    }

    /**
     * Make this the only active period.
     */
    public function activate(): void
    {
        DB::transaction(function (): void {
            static::query()->whereKeyNot($this->id)->where('is_active', true)->update(['is_active' => false]);
            $this->update(['is_active' => true]);
        });
    }

    /**
     * Get the academic year label, e.g. "2025/2026".
     */
    public function academicYear(): string
    {
        return $this->start_year.'/'.($this->start_year + 1);
    }

    /**
     * Get the full label, e.g. "2025/2026 Genap".
     */
    public function label(): string
    {
        return "{$this->academicYear()} {$this->term->label()}";
    }
}
