<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

/**
 * One written-down phone number, read as one international number.
 *
 * Normalisation happens where the number is WRITTEN — RequestContactVerification
 * — and never where it is sent. A channel that cleans up its input carries that
 * cleanup for ever, and the column ends up holding `+97433123456`, `033123456`
 * and `974 3312 3456` as three different people.
 *
 * ponytail: this reads a Qatari keyboard, not the world's. The heuristics below
 * (a leading `0` is a trunk prefix, a number that already opens with the country
 * code is already international) hold for +974, whose mobile numbers are eight
 * digits opening with 3, 5, 6 or 7 — so no local number can be mistaken for one
 * that already carries the country code. THE UPGRADE PATH IS A SECOND COUNTRY:
 * the day one lands, this class is replaced by `giggsey/libphonenumber-for-php`
 * and nothing else changes, because nothing else knows how a number is shaped.
 */
final class PhoneNumber
{
    /** E.164 permits at most fifteen digits; below eight nothing is dialable. */
    private const MIN_DIGITS = 8;

    private const MAX_DIGITS = 15;

    /**
     * @param  string  $defaultCountryCode  digits only, e.g. "974"
     * @return string|null `+` followed by digits, or null when the input cannot
     *                     be read as a number at all — refused rather than
     *                     guessed, because a guessed number reaches a stranger.
     */
    public static function toE164(string $raw, string $defaultCountryCode): ?string
    {
        $trimmed = trim($raw);
        $isInternational = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            return null;
        }

        if (! $isInternational) {
            $digits = self::applyCountryCode($digits, $defaultCountryCode);
        }

        $length = strlen($digits);

        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            return null;
        }

        return '+'.$digits;
    }

    private static function applyCountryCode(string $digits, string $countryCode): string
    {
        // 00 is the international prefix dialled from a local line.
        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $local = ltrim($digits, '0');

            // "0", "00…" already handled; "000" would leave nothing to dial.
            return $local === '' ? $digits : $countryCode.$local;
        }

        return str_starts_with($digits, $countryCode) ? $digits : $countryCode.$digits;
    }

    /**
     * The form a number may appear in inside a log line (FR-022).
     *
     * Enough to tell two recipients apart while reading a delivery log, and not
     * enough to message anyone. A full number in a log is a personal detail
     * sitting in the one place nobody guards and everybody ships to a monitoring
     * vendor.
     */
    public static function mask(string $e164): string
    {
        return strlen($e164) <= 4 ? '****' : str_repeat('*', strlen($e164) - 4).substr($e164, -4);
    }
}
