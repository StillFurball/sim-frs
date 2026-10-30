<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a schedule spreadsheet cannot be imported at all. The message is safe to show to the admin.
 */
class ScheduleImportException extends Exception
{
    //
}
