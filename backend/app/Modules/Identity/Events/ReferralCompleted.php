<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An invitation turned into a real subscription (spec 011 · FR-024 · NFR-005).
 *
 * ⚠️ THREE IDENTIFIERS AND NOTHING ELSE. Gamification decides what a referral is
 * worth — it reads the `invite_friend` catalogue row, which an operator edits —
 * so a value carried here would be the number nobody can find when they go
 * looking for it on the panel. `AwardRequest` refuses values for the same reason
 * one level down.
 *
 * ⚠️ AND NO MODELS. A queued listener deserialises a model by re-fetching it, so
 * a `User` here is two queries and a `ModelNotFoundException` waiting for the
 * first account deletion; the ids are what the award needs and what the
 * idempotency key is built from.
 *
 * `referralId` is that key: `referrals.referred_user_id` is unique, so one
 * referral exists per person for life and its id identifies the CAUSE rather
 * than the moment — which is what stops a redelivery paying twice.
 */
class ReferralCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $referralId,
        public readonly int $referrerUserId,
        public readonly int $referredUserId,
    ) {}
}
