<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * Where a referral has got to (spec 011 · FR-019 · FR-022).
 *
 * ⚠️ `Flagged` PAYS NOTHING AND IS NOT DELETED, which is the whole of FR-022's
 * second half: «the suspicious pattern is marked for review, with no payout». A
 * row silently dropped tells nobody anything and cannot be reviewed; a status
 * that can never complete is what «للمراجعة» actually means.
 *
 * ⚠️ AND `Reversed` IS NOT `Pending`. A refunded subscription does not put the
 * referral back in the queue for the next purchase to complete — the invite was
 * spent, and re-arming it would let one person mint points by subscribing and
 * cancelling on a loop.
 */
enum ReferralStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Flagged = 'flagged';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار أول اشتراك',
            self::Completed => 'مكتملة',
            self::Flagged => 'موقوفة للمراجعة',
            self::Reversed => 'أُلغيت بعد الاسترداد',
        };
    }
}
