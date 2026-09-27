<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

use App\Shared\Support\MinorUnits;

/**
 * Minor units in, readable text out — for message bodies only.
 *
 * Deliberately NOT used by API resources: those send `amount_minor` and
 * `currency` and let the client format. A pre-formatted string on the wire is a
 * number the client has to parse back before it can add anything up, and that
 * parse is where a currency's decimals get guessed.
 */
final class Money
{
    /** The decimals live in {@see MinorUnits} (Shared, so no Payments import). */
    public static function format(int $minor, string $currency): string
    {
        return (string) MinorUnits::display($minor, $currency);
    }
}
