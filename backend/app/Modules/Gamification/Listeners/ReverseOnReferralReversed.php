<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Gamification\Actions\ReverseAward;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\Identity\Events\ReferralReversed;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The subscription was refunded, so both awards go back (011 · FR-021 · SC-007).
 *
 * ⚠️ THE ENTRIES ARE FOUND BY THE IDEMPOTENCY TRIPLET, NOT THROUGH A COLUMN ON
 * THE REFERRAL. `data-model.md` proposed a single `award_entry_id`, and one
 * column holds one entry while the completion pays TWO people — so a reversal
 * driven through it would return the inviter's points and silently leave the
 * invited student's, for ever. `(action_key, source_type, source_id)` finds both,
 * and finds exactly the rows the award wrote.
 *
 * ⚠️ `reversal_of_id = 0` EXCLUDES THE COMPENSATING ROWS THEMSELVES. Zero is the
 * sentinel for «this is an original», never NULL — `NULL != NULL` in the unique
 * index, so a nullable discriminator would stop the guard biting for ordinary
 * awards instead. Without this clause a second refund event would try to reverse
 * a reversal, which `ReverseAward` throws on.
 *
 * ⚠️ AND `SC-007` IS MEASURED ON THE AGGREGATE RETURNING TO ITS PRE-AWARD VALUE,
 * never on a row count: counting two rows passes against a design that writes a
 * compensating entry and moves nothing.
 */
class ReverseOnReferralReversed implements ShouldQueue
{
    public function __construct(private readonly ReverseAward $reverse) {}

    public function handle(ReferralReversed $event): void
    {
        $entries = AwardEntry::query()
            ->where('action_key', 'invite_friend')
            ->where('source_type', 'referral')
            ->where('source_id', $event->referralId)
            ->where('reversal_of_id', 0)
            ->get();

        foreach ($entries as $entry) {
            $this->reverse->handle($entry);
        }
    }
}
