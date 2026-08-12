<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

/**
 * The SECOND scrub — the one that does not trust the provider to have done it.
 *
 * ⚠️ WHY A SECOND ONE EXISTS AT ALL. The first lives inside `parseCallback()`,
 * behind the signature gate. But a callback whose signature FAILED is stored
 * too — SC-002 asks for refusal AND a record — and its payload is the attacker's
 * raw body, which never reached a provider's parser. A string shaped like a card
 * number would land in the column and live in every backup taken afterwards.
 *
 * So this runs on the way to the database, on both paths, and it is deliberately
 * provider-agnostic: it knows nothing about anyone's schema, only about what
 * must never be stored.
 *
 * Three defences, in order:
 *   1. depth and size — an attacker also controls how BIG the body is;
 *   2. key names — anything whose name says secret is dropped entirely;
 *   3. values — a digit run of 13–19 with a valid Luhn check is a card number
 *      whatever key it arrived under, and a long opaque token is treated the
 *      same way.
 *
 * ⚠️ Luhn, not "13 or more digits": an order id, a phone number and a timestamp
 * are all digit runs, and redacting them would empty the record this class exists
 * to preserve. Luhn is what a card number passes and a serial number does not.
 */
final class CallbackPayloadSanitizer
{
    /** Beyond this the body is not evidence, it is a payload. */
    private const MAX_KEYS = 100;

    private const MAX_DEPTH = 5;

    private const MAX_STRING = 500;

    private const REDACTED = '[redacted]';

    /** Names that carry a secret whatever the value looks like. */
    private const FORBIDDEN_KEYS = [
        'card', 'pan', 'cvv', 'cvc', 'cardholder', 'expiry', 'exp_month', 'exp_year',
        'secret', 'password', 'signature', 'token', 'api_key', 'apikey', 'authorization',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function scrub(array $payload, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_truncated' => 'max depth'];
        }

        $clean = [];
        $seen = 0;

        foreach ($payload as $key => $value) {
            if (++$seen > self::MAX_KEYS) {
                $clean['_truncated'] = 'max keys';
                break;
            }

            if (self::isForbiddenKey((string) $key)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => self::scrub($value, $depth + 1),
                is_string($value) => self::scrubString($value),
                is_scalar($value), $value === null => $value,
                // An object, a resource — not something a JSON body produces, so
                // not something to store.
                default => self::REDACTED,
            };
        }

        return $clean;
    }

    private static function isForbiddenKey(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            if (str_contains($needle, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    private static function scrubString(string $value): string
    {
        if (self::looksLikeCardNumber($value)) {
            return self::REDACTED;
        }

        return mb_strlen($value) > self::MAX_STRING
            ? mb_substr($value, 0, self::MAX_STRING).'…'
            : $value;
    }

    /**
     * A digit run of 13–19 that passes Luhn, with spaces and dashes ignored
     * because that is how a human types one.
     */
    private static function looksLikeCardNumber(string $value): bool
    {
        $digits = preg_replace('/[\s-]/', '', $value) ?? '';

        if (preg_match('/^\d{13,19}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
