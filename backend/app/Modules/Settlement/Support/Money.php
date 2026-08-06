<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

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
    /** Every supported currency has two decimals today; the constant says so out loud. */
    private const MINOR_UNITS = 100;

    public static function format(int $minor, string $currency): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return sprintf(
            '%s%s.%02d %s',
            $sign,
            number_format(intdiv($absolute, self::MINOR_UNITS)),
            $absolute % self::MINOR_UNITS,
            $currency,
        );
    }
}
