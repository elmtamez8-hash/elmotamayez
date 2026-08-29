<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Models\GamificationAction;
use App\Modules\Identity\Events\ReferralCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * An invitation turned into a real subscription ⇒ points for BOTH (011 · FR-020 · FR-024).
 *
 * ⚠️ TWO AWARDS, ONE SOURCE. «للطرفَين» — the inviter and the person invited —
 * and the idempotency key is `(student, action, source_type, source_id)`, so the
 * two rows differ only in the student and neither collides with the other. The
 * referral id is the source because `referrals.referred_user_id` is unique: one
 * referral per person for life, so the id names the CAUSE and not the moment,
 * and a redelivered event pays nothing the second time.
 *
 * ⚠️ NO `workspaceId`, AND THE CATALOGUE ROW MUST CARRY ZERO COINS. A referral
 * belongs to no teacher, so there is no purse — and `AwardPoints` THROWS rather
 * than guess one. Give `invite_friend` coins from the panel and every completed
 * referral becomes a 500 inside this queued listener; the migration that seeds
 * the row says so beside the value.
 *
 * ⚠️ AND THIS LISTENER LIVES IN GAMIFICATION, NOT IN IDENTITY. Nothing outside
 * this module names `AwardPoints` — Constitution III — which is also why
 * `referrals` carries no `award_entry_id`: the entries belong here, and one
 * column could hold only one of the two.
 */
class AwardOnReferralCompleted implements ShouldQueue
{
    public function __construct(private readonly AwardPoints $award) {}

    public function handle(ReferralCompleted $event): void
    {
        if (! $this->payable()) {
            return;
        }

        foreach ([$event->referrerUserId, $event->referredUserId] as $userId) {
            $this->award->handle(new AwardRequest(
                studentUserId: $userId,
                actionKey: 'invite_friend',
                sourceType: 'referral',
                sourceId: $event->referralId,
            ));
        }
    }

    /**
     * ⚠️ THE ROW IS `/admin`-EDITABLE, AND A COIN VALUE TYPED INTO IT WOULD
     * THROW IN A QUEUED JOB ON A REFERRAL THAT IS ALREADY `completed`.
     *
     * `AwardPoints` refuses a coin-bearing action with no workspace rather than
     * guessing a purse — correctly, since a referral belongs to no teacher. But
     * an operator looking at `session_attended: 5 coins` beside
     * `invite_friend: 0 coins` has every reason to «fix» the zero, and the flip
     * to `completed` has ALREADY happened by the time this listener runs: the
     * job throws, Horizon retries it, it throws again, and the referral is left
     * completed-but-unpaid — the exact state the cap-before-flip design and the
     * absent `daily_cap` both exist to prevent, reached through a text box.
     *
     * So the misconfiguration is caught here and REPORTED, not raised. The row
     * is named in the log because that is the one thing an operator needs to
     * undo it, and a `failed_jobs` stack trace naming `AwardPoints` would send
     * whoever reads it to the wrong file.
     */
    private function payable(): bool
    {
        $action = GamificationAction::query()->where('key', 'invite_friend')->first();

        if ($action === null || (int) $action->coins === 0) {
            // No row is not an error — an award for an undefined action is an
            // unfilled catalogue, and `AwardPoints` says so itself by returning.
            return true;
        }

        Log::warning('لن تُصرَف نقاط الدعوة: صفّ invite_friend يمنح عملات، والدعوة بلا مساحة عمل تضعها فيها. اضبط العملات على صفر من لوحة الإدارة.', [
            'action_key' => 'invite_friend',
            'coins' => (int) $action->coins,
        ]);

        return false;
    }
}
