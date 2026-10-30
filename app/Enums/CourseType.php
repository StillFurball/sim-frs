<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum CourseType: string
{
    case Mandatory = 'wajib';
    case Elective = 'pilihan';
    case General = 'umum';

    /**
     * Get the human readable label for the course type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Mandatory => 'Wajib',
            self::Elective => 'Pilihan',
            self::General => 'MK Umum',
        };
    }

    /**
     * Get the Flux badge color for the course type.
     */
    public function color(): string
    {
        return match ($this) {
            self::Mandatory => 'red',
            self::Elective => 'blue',
            self::General => 'amber',
        };
    }

    /**
     * Resolve a course type from a free-form spreadsheet value such as "Wajib (kurikulum sem 6)" or "SKPB (MK Umum)".
     */
    public static function fromLabel(?string $value): ?self
    {
        $normalized = Str::lower(trim((string) $value));

        return match (true) {
            $normalized === '' => null,
            str_contains($normalized, 'wajib') => self::Mandatory,
            str_contains($normalized, 'pilihan') => self::Elective,
            str_contains($normalized, 'umum'), str_contains($normalized, 'skpb') => self::General,
            default => null,
        };
    }
}
