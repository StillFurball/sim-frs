<?php

namespace App\Support;

/**
 * The cell values of one worksheet, plus the font emphasis of bold or italic cells and the colour of filled cells.
 */
final readonly class Worksheet
{
    /**
     * @param  list<list<string|null>>  $rows  Dense rows of cell strings (empty cells are null), zero-based.
     * @param  array<int, array<int, array{bold: bool, italic: bool}>>  $fonts  Emphasis keyed by zero-based row and column.
     * @param  array<int, array<int, string>>  $fills  Solid ARGB fill colours (e.g. "FFD8B088") keyed by zero-based row and column.
     */
    public function __construct(
        public array $rows,
        public array $fonts = [],
        public array $fills = [],
    ) {}

    /**
     * Get the solid ARGB fill colour of a cell, or null when it has none.
     */
    public function fill(int $row, int $column): ?string
    {
        return $this->fills[$row][$column] ?? null;
    }

    /**
     * Get the value of a cell, or null when it is empty.
     */
    public function value(int $row, int $column): ?string
    {
        return $this->rows[$row][$column] ?? null;
    }

    /**
     * Determine whether the cell is formatted bold.
     */
    public function isBold(int $row, int $column): bool
    {
        return $this->fonts[$row][$column]['bold'] ?? false;
    }

    /**
     * Determine whether the cell is formatted italic.
     */
    public function isItalic(int $row, int $column): bool
    {
        return $this->fonts[$row][$column]['italic'] ?? false;
    }
}
