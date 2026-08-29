<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Listeners\CompleteReferral;
use App\Modules\Identity\Models\Referral;
use App\Modules\Identity\Models\ReferralCode;
use App\Modules\Identity\Support\ReferralStatus;
use App\Shared\Actions\Action;
use Illuminate\Database\QueryException;

/**
 * Record who invited this new account (spec 011 · FR-019 · FR-022).
 *
 * ⚠️ IT NEVER FAILS A REGISTRATION, AND THAT IS THE MOST IMPORTANT LINE HERE. A
 * code mistyped off a poster, a campaign that ended yesterday, a duplicate
 * submit — every one of them returns null and lets the account be created. The
 * alternative is a signup form that refuses a real person over a marketing
 * string they do not control, which is the most hostile thing this feature could
 * possibly do. Nothing is owed until somebody subscribes, so attaching nothing
 * costs nothing.
 *
 * ⚠️ NOTHING IS PAID HERE EITHER (FR-019 · SC-006). A `pending` row is a claim,
 * not a reward: the points are awarded when the invited person actually
 * subscribes, by {@see CompleteReferral}. Paying
 * on registration would make `POST /auth/register` a free mint — which is why
 * that route getting its rate limiter (`b40ec73`) was a precondition for this
 * whole wave.
 *
 * ⚠️ SELF-REFERRAL IS A `flagged` ROW, NOT A DISCARDED ONE (FR-022). «The
 * suspicious pattern is marked for review, without paying out» — a row silently
 * dropped tells nobody anything and cannot be reviewed. The detection is the
 * identity equality and nothing cleverer: an email heuristic or an IP match
 * would flag real families sharing a household, and the actual defence against
 * somebody with a hundred throwaway addresses is the CAP, which no equality
 * check could ever provide.
 */
class AttachReferral extends Action
{
    public function handle(User $referred, ?string $code): ?Referral
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $row = ReferralCode::query()
            ->where('code', ReferralCode::normalise($code))
            ->first();

        if ($row === null) {
            // A typo, or a code that never existed. Silence: the account is being
            // created and this is not the moment to argue about a poster.
            return null;
        }

        $selfReferral = (int) $row->user_id === (int) $referred->getKey();

        try {
            $referral = Referral::create([
                'referrer_user_id' => $row->user_id,
                'referred_user_id' => $referred->getKey(),
                'flagged_reason' => $selfReferral ? 'إحالة ذاتية: المُحيل والمُحال شخص واحد.' : null,
            ]);
        } catch (QueryException) {
            // `unique(referred_user_id)` — this person already has a referral, and
            // one per person is for life. A second attach is not an error worth
            // failing a registration over.
            return null;
        }

        if ($selfReferral) {
            // Written after the insert rather than passed to it: `status` is not
            // `$fillable`, because every transition in this model's life is a
            // conditional UPDATE and a mass-assignable status is a second way to
            // complete a referral from outside the statement that owns it.
            $referral->forceFill(['status' => ReferralStatus::Flagged->value])->save();
        }

        return $referral;
    }
}
