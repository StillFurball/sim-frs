<?php

namespace App\Enums;

enum Term: int
{
    case Ganjil = 1;
    case Genap = 2;

    /**
     * Get the human readable label for the term.
     */
    public function label(): string
    {
        return $this->name;
    }
}
