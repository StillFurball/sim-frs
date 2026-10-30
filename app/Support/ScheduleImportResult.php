<?php

namespace App\Support;

/**
 * Summary of a schedule spreadsheet import.
 */
final readonly class ScheduleImportResult
{
    /**
     * @param  list<string>  $errors  Row-level problems, each prefixed with the spreadsheet row number.
     */
    public function __construct(
        public int $coursesCreated,
        public int $classesCreated,
        public int $classesUpdated,
        public int $rowsSkipped,
        public array $errors,
    ) {}

    /**
     * Get the number of classes that were written to the database.
     */
    public function classesImported(): int
    {
        return $this->classesCreated + $this->classesUpdated;
    }
}
