<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Models\ReferralCode;
use App\Shared\Actions\Action;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * The one code this person will ever have (spec 011 · FR-018).
 *
 * ⚠️ IT RACES WITH ITSELF, AND IT IS REACHED FROM A `GET`. Two concurrent loads
 * of the referrals page both find no row and both insert — and the loser gets a
 * `QueryException` rendered as a **500 on a read**, which is the shape a user
 * reports as «the page works sometimes». Two different unique indexes can bite
 * here and they need different answers, which is why this is a loop and not a
 * single `firstOrCreate`:
 *
 *   · `user_id` — the other request won; re-reading finds their row, and their
 *     row is just as good as ours. The loop top absorbs it.
 *   · `code` — a random collision. Re-reading finds nothing, so the next pass
 *     generates a DIFFERENT code. A `firstOrCreate` alone would retry the same
 *     colliding value for ever.
 *
 * ⚠️ AND NOT `insertOrIgnore`, which writes a row without booting the model, so
 * `HasUuid` never fires — on MySQL the NOT NULL violation is downgraded to a
 * warning, `''` is stored, and every later code on the platform collides with
 * that row on `unique(uuid)` and is silently skipped. The rule this repository
 * wrote down over `CreditLedger`: `insertOrIgnore` is for a caller that supplies
 * `uuid` and `created_at` explicitly, and this one does not need to.
 */
class IssueReferralCode extends Action
{
    /** Enough for a collision on an 8-character alphabet to be a rounding error. */
    private const ATTEMPTS = 5;

    public function handle(User $user): ReferralCode
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $existing = ReferralCode::query()->where('user_id', $user->getKey())->first();

            if ($existing !== null) {
                return $existing;
            }

            try {
                return ReferralCode::create([
                    'user_id' => $user->getKey(),
                    'code' => ReferralCode::generate(),
                ]);
            } catch (QueryException) {
                // Either index. The loop top tells the two cases apart by asking
                // the question again rather than by parsing a driver message —
                // which differs between MySQL and SQLite, so a test written
                // against one would prove nothing about the other.
                continue;
            }
        }

        throw new RuntimeException("Could not issue a referral code for user {$user->getKey()} after ".self::ATTEMPTS.' attempts.');
    }
}
