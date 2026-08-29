<?php

declare(strict_types=1);

use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 011 · T081 — the row without which the whole referral feature is silent.
 *
 * ⚠️ SHIPPED IN THE SAME BATCH AS THE LISTENER, OR THE REWARD IS A SILENT ZERO.
 * `AwardPoints` looks an action up by key and returns when there is no row — an
 * award for an undefined action is an unfilled catalogue, not an error. So the
 * event, the listener and the endpoint can all be correct and a completed
 * referral pays nothing at all, with nothing logged. `helpful_answer` shipped
 * exactly that way in 010 and was found by walking the product, never by a test:
 * `tests/Pest.php` seeds the catalogue before every case, so every assertion
 * about it was made against a table production did not have.
 *
 * ⚠️ `firstOrCreate`, NEVER `updateOrCreate`. Every catalogue row is editable
 * from `/admin`, so an overwrite in a deploy path resets an xp value an operator
 * tuned — on every release, silently. This is the fourth instance of that shape
 * in this tree.
 *
 * ⚠️ AND THE VALUE COMES FROM `referral.reward_points` HERE AND NOWHERE ELSE.
 * That is the seam FR-023 needs: the number is configurable on the way in, and
 * the CATALOGUE is authoritative from then on — because `AwardRequest`
 * deliberately carries no values and `AwardPoints` reads the row. A setting
 * re-read at award time would be a second live source for one number, and the
 * operator editing the panel would be editing the one nobody reads.
 *
 * `down()` is empty on purpose: removing the row on a rollback stops awarding
 * points that were being awarded a moment earlier.
 */
return new class extends Migration
{
    public function up(): void
    {
        GamificationAction::query()->firstOrCreate(
            ['key' => 'invite_friend'],
            [
                'name_ar' => 'دعوة صديق اشترك فعلاً',
                'xp' => (int) PlatformSettings::get('referral.reward_points', config('referral.reward_points', 50)),
                // ⚠️ ZERO, AND NOT NEGOTIABLE. A referral belongs to no teacher,
                // so there is no purse for coins — and `AwardPoints` throws on a
                // coin-bearing action with a null workspace rather than guessing
                // one. Coins here would make every completed referral a 500
                // inside a queued listener.
                'coins' => 0,
                // Null: past a daily cap `AwardPoints` returns null, which would
                // leave a referral flipped `completed` with nothing awarded. The
                // governor is `referral.max_completed_per_referrer`, checked
                // before the flip.
                'daily_cap' => null,
                'is_active' => true,
            ],
        );
    }

    public function down(): void {}
};
