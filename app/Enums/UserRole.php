<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Student = 'student';

    /**
     * Get the human readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Student => 'Mahasiswa',
        };
    }
}
