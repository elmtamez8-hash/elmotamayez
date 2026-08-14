<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Http\Resources\CollectionRowResource;

/**
 * What a payment payload may carry, and what must never appear in one (FR-030 · SC-012).
 *
 * ⚠️ WHAT THIS GUARDS, EXACTLY. `PaymentExposureTest` fetches an ENUMERATED LIST
 * of this phase's payloads — the collection report, its export, the payment
 * status read, the audit chain, the reconciliation snapshot — and walks each one
 * through {@see self::leaks()}. Nothing sweeps the module for new endpoints.
 *
 * ⚠️ WHAT IT DOES NOT GUARD. A payload added later and not added to that list is
 * unchecked, and this class cannot tell. That is written here rather than left
 * implied, because a guard described in a header and not written is worse than
 * no guard at all: it is read as coverage. The same admission is on
 * {@see StudentBalanceAllowlist}, and for the same reason — a module-wide sweep
 * is impossible here, since `OrderResource` sends a student the amount they owe
 * entirely by right and any blanket scan would fail on the day it was written.
 *
 * ⚠️ AND THE VALUE IS CHECKED AS WELL AS THE NAME. A list of field names cannot
 * see a card number that arrived inside `payload` under the key `note` — and
 * `payload` is a free column written by the other party. The value test is
 * {@see CallbackPayloadSanitizer}'s Luhn check, CALLED rather than copied: two
 * card detectors drift, and the one that drifts is always the one nobody reads.
 */
final class PaymentFieldAllowlist
{
    /**
     * The fields a collection row may carry.
     *
     * `teacher_rate_minor` is absent and its absence is the point: FR-035 forbids
     * a teacher's settlement rate in any payload of this phase, and the column
     * sitting on `credit_purchases` is that rate under its own name. See
     * {@see CollectionRowResource} for why
     * the line is worth drawing even though the number is derivable.
     *
     * @return list<string>
     */
    public static function collectionRow(): array
    {
        return [
            'uuid',
            'occurred_at',
            'settled_at',
            'status',
            'method',
            'provider',
            'amount_minor',
            'currency',
            'order_uuid',
            'source',
            'student_uuid',
            'student_name',
            'pricing',
        ];
    }

    /**
     * Key names that must never appear in any payload of this phase, at any depth.
     *
     * `reference` is absent from this list on purpose: a provider's own
     * transaction reference is not an instrument detail, it is the handle an
     * operator quotes when they telephone the gateway. `payload` IS here — the
     * raw body is evidence for the sweep, never something a screen renders.
     *
     * @return list<string>
     */
    public static function forbiddenKeys(): array
    {
        return array_values(array_unique(array_merge(
            CallbackPayloadSanitizer::forbiddenKeys(),
            ['payload', 'iban', 'account_number', 'raw_body'],
        )));
    }

    /**
     * Everything wrong with this payload, by path. Empty means clean.
     *
     * @return list<string>
     */
    public static function leaks(mixed $payload, string $path = ''): array
    {
        $found = [];

        if (is_array($payload)) {
            foreach ($payload as $key => $value) {
                $here = $path === '' ? (string) $key : $path.'.'.$key;

                if (is_string($key) && self::isForbiddenKey($key)) {
                    $found[] = 'forbidden key: '.$here;

                    continue;
                }

                $found = array_merge($found, self::leaks($value, $here));
            }

            return $found;
        }

        // A number is not exempt: JSON carries a card number as an integer just
        // as happily as it carries one as a string.
        if (is_string($payload) || is_int($payload)) {
            if (CallbackPayloadSanitizer::looksLikeCardNumber((string) $payload)) {
                $found[] = 'card-shaped value: '.$path;
            }
        }

        return $found;
    }

    private static function isForbiddenKey(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::forbiddenKeys() as $forbidden) {
            if (str_contains($needle, $forbidden)) {
                return true;
            }
        }

        return false;
    }
}
