<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * One CSV cell, made safe to open in a spreadsheet.
 *
 * ⚠️ FORMULA INJECTION. Excel, LibreOffice and Google Sheets execute a cell that
 * begins with `=`, `+`, `-` or `@` (and a leading tab or carriage return is read
 * past to reach one). A student who names themselves `=HYPERLINK("http://x",
 * "click")` puts a live link — or a DDE payload — in front of the finance officer
 * who opens the export. The OWASP remedy is a leading single quote, which the
 * spreadsheet shows as text and never evaluates.
 *
 * ⚠️ NUMBERS PASS UNTOUCHED, AND THAT IS NOT A LOOPHOLE. A money column carries
 * `-500` for a deduction; quoting it would turn every negative amount in an
 * accounting export into text that no SUM adds up. A string that is purely
 * numeric cannot be a formula.
 */
final class CsvCell
{
    public static function safe(string $value): string
    {
        if ($value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$value
            : $value;
    }
}
