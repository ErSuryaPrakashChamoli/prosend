<?php

namespace App\Services\Imports;

use RuntimeException;

/**
 * A workbook that cannot be imported. The message is written for the person who uploaded it.
 */
class UnreadableSpreadsheet extends RuntimeException
{
    public static function notAWorkbook(): self
    {
        return new self('This file is not a readable Excel workbook. Open it in Excel and save it again as .xlsx, or save it as CSV.');
    }

    public static function empty(): self
    {
        return new self('The first visible worksheet is empty. Put the contacts on the first worksheet, with a header row.');
    }
}
