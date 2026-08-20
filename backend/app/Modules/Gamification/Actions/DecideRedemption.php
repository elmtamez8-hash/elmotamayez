<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Models\User;
use App\Modules\Gamification\Enums\RedemptionStatus;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Gamification\Support\ProgressWriter;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Fulfil or reject a redemption request (FR-035).
 *
 * ⚠️ IT BEGINS WITH AN ATOMIC CLAIM ON THE STATUS, and that is the whole of this
 * class's correctness. Two clicks on "reject" that both read `pending` refund the
 * coins TWICE — coins created out of nothing, which is the direction FR-034 does
 * NOT guard: that requirement is written about balances going negative.
 *
 * ⚠️ AND THE RELEASE IS TWO STATEMENTS, NOT ONE (research §R7). Stock comes back
 * unconditionally; the monthly counter comes back only if the reward is still on
 * the month this request consumed. Folded into one statement conditioned on the
 * current month, a rejection after the month rolls over matches nothing and LOSES
 * A UNIT OF STOCK PERMANENTLY for a reward nobody ever received.
 */
class DecideRedemption extends Action
{
    public function __construct(private readonly ProgressWriter $progress) {}

    /** @return bool false when it was already decided */
    public function handle(Redemption $redemption, User $decider, RedemptionStatus $outcome): bool
    {
        return DB::transaction(function () use ($redemption, $decider, $outcome): bool {
            $claimed = DB::table('redemptions')
                ->where('id', $redemption->getKey())
                ->where('status', RedemptionStatus::Pending->value)
                ->update([
                    'status' => $outcome->value,
                    'decided_by' => $decider->getKey(),
                    'decided_at' => now(),
                    'updated_at' => now(),
                ]) > 0;

            if (! $claimed) {
                return false;
            }

            if ($outcome === RedemptionStatus::Rejected) {
                $this->release($redemption);
            }

            return true;
        });
    }

    private function release(Redemption $redemption): void
    {
        // The coins, in full (FR-035 · SC-013).
        $this->progress->moveCoins(
            (int) $redemption->user_id,
            (int) $redemption->workspace_id,
            $redemption->coins_spent,
        );

        // Stock: unconditional. The unit was never handed over.
        DB::table('rewards')
            ->where('id', $redemption->reward_id)
            ->increment('stock', 1, ['updated_at' => now()]);

        /*
        | The monthly counter: only while the reward is still on the month this
        | request consumed. Past that, the counter has already been reset to a new
        | month and decrementing it would give away a slot from the WRONG month.
        */
        DB::table('rewards')
            ->where('id', $redemption->reward_id)
            ->where('month_key', $redemption->claimed_month_key)
            ->where('month_redeemed', '>', 0)
            ->update([
                'month_redeemed' => DB::raw('month_redeemed - 1'),
                'updated_at' => now(),
            ]);
    }
}
