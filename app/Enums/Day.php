<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum Day: int
{
    case Senin = 1;
    case Selasa = 2;
    case Rabu = 3;
    case Kamis = 4;
    case Jumat = 5;
    case Sabtu = 6;

    /**
     * Get the human readable label for the day.
     */
    public function label(): string
    {
        return match ($this) {
            self::Jumat => "Jum'at",
            default => $this->name,
        };
    }

    /**
     * Resolve a day from an Indonesian or English day name, e.g. "Jum'at", "jumat" or "Friday".
     */
    public static function fromLabel(?string $value): ?self
    {
        $normalized = preg_replace('/[^a-z]/', '', Str::lower((string) $value));

        return match ($normalized) {
            'senin', 'monday' => self::Senin,
            'selasa', 'tuesday' => self::Selasa,
            'rabu', 'wednesday' => self::Rabu,
            'kamis', 'thursday' => self::Kamis,
            'jumat', 'friday' => self::Jumat,
            'sabtu', 'saturday' => self::Sabtu,
            default => null,
        };
    }
}
