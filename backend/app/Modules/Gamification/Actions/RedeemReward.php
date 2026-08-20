<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Models\User;
use App\Modules\Gamification\Enums\RedemptionStatus;
use App\Modules\Gamification\Enums\RewardType;
use App\Modules\Gamification\Events\RewardRedeemed;
use App\Modules\Gamification\Exceptions\RedemptionRefused;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Gamification\Support\ProgressWriter;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Spend coins on a reward (FR-032 … FR-035).
 *
 * ⚠️ THE REWARD IS RESOLVED BY UUID *INSIDE* THIS ACTION, AFTER THE ENROLMENT
 * CHECK — never by an implicit route binding. `WorkspaceScope` is INERT for a
 * student: they are a member of no workspace at all (only AcceptInvitation and
 * CreateWorkspace write that pivot), so `WorkspaceContext::id()` is null and the
 * scope adds no condition. A bound `{reward}` would therefore resolve ANY
 * teacher's reward, and BelongsToWorkspace would not stop it.
 */
class RedeemReward extends Action
{
    public function __construct(
        private readonly GamificationCalendar $calendar,
        private readonly ProgressWriter $progress,
        private readonly EnrollmentDirectory $enrollments,
    ) {}

    public function handle(User $student, string $rewardUuid): Redemption
    {
        $reward = Reward::query()->withoutWorkspaceScope()->where('uuid', $rewardUuid)->first();

        /*
        | One answer for "no such reward" and "not your teacher's shop" (FR-037).
        | Two different answers would let a student walk the uuid space and learn
        | what every other teacher sells.
        */
        abort_if(
            $reward === null
            || ! $this->enrollments->hasActiveEnrollmentInWorkspace($student, (int) $reward->workspace_id),
            403,
            'هذه المكافأة ليست في متجر مدرّسك.',
        );

        $monthKey = substr($this->calendar->dayKey(), 0, 7);

        return DB::transaction(function () use ($student, $reward, $monthKey): Redemption {
            if (! $this->claim($reward, $monthKey)) {
                // Only now, and only to compose a sentence.
                throw $this->explain($reward, $monthKey);
            }

            $balance = $this->progress->coinBalanceFor((int) $student->getKey(), (int) $reward->workspace_id);

            if (! $this->progress->moveCoins(
                (int) $student->getKey(),
                (int) $reward->workspace_id,
                -$reward->price_coins,
            )) {
                // The conditional deduction matched nothing: not enough coins.
                // Throwing rolls the claim above back with it, so the stock and
                // the monthly counter are returned in the same breath.
                throw RedemptionRefused::insufficientCoins();
            }

            unset($balance);

            $redemption = Redemption::query()->create([
                'user_id' => $student->getKey(),
                'workspace_id' => $reward->workspace_id,
                'reward_id' => $reward->getKey(),
                // Frozen: the teacher may re-price the reward tomorrow, and a
                // refusal must return what was actually taken.
                'coins_spent' => $reward->price_coins,
                // ⚠️ WHICH MONTH'S COUNTER THIS CONSUMED. Without it, a rejection
                // after the month rolls over finds `month_key` already moved on,
                // matches nothing, and loses a unit of stock for ever.
                'claimed_month_key' => $monthKey,
                'status' => RedemptionStatus::Pending,
            ]);

            /*
            | A shield is fungible, so it is a counter rather than a row — and it
            | is credited at REDEMPTION rather than at fulfilment, because there is
            | nothing for the teacher to do: the platform is the one that honours
            | it. Every other type waits for a person.
            */
            if ($reward->type === RewardType::StreakShield) {
                /*
                | ⚠️ THE ROW IS ENSURED FIRST. A student can buy a shield before
                | they have ever been awarded anything — coins can arrive from a
                | teacher's own grant — and an increment against a row that does
                | not exist affects zero rows, takes the coins, and credits
                | nothing. Silently.
                */
                $this->progress->progressFor((int) $student->getKey());

                DB::table('student_progress')
                    ->where('user_id', $student->getKey())
                    ->increment('shield_count', 1, ['updated_at' => now()]);
            }

            DB::afterCommit(fn () => event(new RewardRedeemed($redemption)));

            return $redemption;
        });
    }

    /**
     * Stock and the monthly slot, claimed in ONE statement (research §R7).
     *
     * ⚠️ `monthly_cap IS NULL` IS LOAD-BEARING. Without it every redemption after
     * the first in a month is refused on an UNCAPPED reward — including the streak
     * shield, which is the one thing students buy repeatedly.
     *
     * ⚠️ AND `month_redeemed` IS ASSIGNED BEFORE `month_key`, WHICH IS NOT
     * COSMETIC. MySQL evaluates SET clauses left to right against the values
     * already updated in the same statement, while SQLite evaluates them all
     * against the original row. With the order reversed, MySQL would compare
     * `month_key` to itself, always take the `+ 1` branch, and never reset the
     * counter at the turn of the month — and no local test could see it.
     */
    private function claim(Reward $reward, string $monthKey): bool
    {
        /*
        | Raw and fully parameterised, because the CASE has to carry a bound value
        | and the builder's `update()` cannot bind inside a raw expression. It
        | returns the affected-row count, which is the whole answer: zero means
        | refused.
        */
        $affected = DB::affectingStatement(
            <<<'SQL'
                UPDATE rewards
                   SET stock = stock - 1,
                       month_redeemed = CASE WHEN month_key = ? THEN month_redeemed + 1 ELSE 1 END,
                       month_key = ?,
                       updated_at = ?
                 WHERE id = ?
                   AND is_active = 1
                   AND stock > 0
                   AND (month_key <> ? OR monthly_cap IS NULL OR month_redeemed < monthly_cap)
                SQL,
            [$monthKey, $monthKey, now(), $reward->getKey(), $monthKey],
        );

        return $affected > 0;
    }

    /** Why the claim matched nothing — asked once, after the fact. */
    private function explain(Reward $reward, string $monthKey): RedemptionRefused
    {
        $fresh = Reward::query()->withoutWorkspaceScope()->where('id', $reward->getKey())->first();

        if ($fresh === null || ! $fresh->is_active) {
            return RedemptionRefused::inactive();
        }

        if ($fresh->stock <= 0) {
            return RedemptionRefused::outOfStock();
        }

        if ($fresh->monthly_cap !== null && $fresh->month_key === $monthKey && $fresh->month_redeemed >= $fresh->monthly_cap) {
            return RedemptionRefused::monthlyCapReached();
        }

        // The row moved between the claim and this read. Out of stock is the
        // likeliest and the least misleading thing to say.
        return RedemptionRefused::outOfStock();
    }
}
