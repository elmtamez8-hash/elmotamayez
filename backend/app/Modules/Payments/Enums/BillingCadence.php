<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * How big one payment is: a session, half a month, or a month.
 *
 * ⚠️ A SECOND AXIS, deliberately not folded into {@see BillingMode}.
 *
 * The mode answers *how the money arrives and whether a debt is allowed*
 * (credits up front, collected by hand, through a gateway, or a mix). This
 * answers *how much is settled at once*. They are independent: a teacher can
 * take prepaid credits a session at a time or a month at a time, and can defer
 * either one.
 *
 * Multiplying them into a single enum would produce twelve cases, most of them
 * meaningless, and every one of them a branch someone has to keep correct. Two
 * fields, read from one place (FR-013).
 *
 * And it gets NO branch of its own in the floor predicate. Cadence expresses
 * itself through two knobs that already exist:
 *
 *   - prepaid — the package size a workspace offers by default;
 *   - deferred — how many sessions may stand unpaid, i.e. the starting credit
 *     limit. "Postpaid per session" is a ceiling of one; "postpaid monthly" is a
 *     month's worth.
 *
 * A third copy of the floor decision is a third copy that drifts.
 */
/*
| ⚠️ The stored values carry a `per_` prefix, and that is not decoration.
|
| A bare `'session'` is one of the most common words in this product — it appears
| in LiveSessions resources, in the broadcast provider, in the playback guard.
| SingleSourceOfModeTest forbids any file outside this enum and BillingSettings
| from naming a billing value, and with the bare form that guard fired on three
| files that have nothing to do with billing. The choice was to weaken the guard
| or to make the value unambiguous; the value is cheaper.
*/
enum BillingCadence: string
{
    /** The launch default: one session paid for at a time. */
    case Session = 'per_session';

    case HalfMonth = 'per_half_month';

    case Month = 'per_month';

    public function label(): string
    {
        return match ($this) {
            self::Session => 'لكل حصة',
            self::HalfMonth => 'نصف شهري',
            self::Month => 'شهري',
        };
    }

    /**
     * How many sessions one payment covers, by convention.
     *
     * A convention, not a measurement: the real number depends on how often a
     * given teacher meets a given student, which nothing here knows. It sets the
     * DEFAULT package size and the DEFAULT credit ceiling, both of which an
     * operator overrides per workspace and per student (FR-038).
     *
     * Read from config so a platform whose students meet twice a week rather
     * than once does not need a deploy to say so.
     */
    public function sessionsPerCycle(): int
    {
        $configured = config('billing.cadence_sessions.'.$this->value);

        return is_numeric($configured) ? max(1, (int) $configured) : match ($this) {
            self::Session => 1,
            self::HalfMonth => 4,
            self::Month => 8,
        };
    }
}
