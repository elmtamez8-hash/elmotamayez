<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Assessments\Events\AttemptFinalized;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Actions\ReinstateAward;
use App\Modules\Gamification\Actions\ReverseAward;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\Gamification\Support\AwardChain;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * A paper is marked ⇒ the `exam_passed` points follow its verdict.
 *
 * ⚠️ PRACTICE ATTEMPTS EARN NOTHING HERE. `AttemptFinalized` fires for them too
 * (its own docblock says so, and spec 009 is the reason it does) — but a practice
 * run is unlimited, instantly markable and self-chosen, so paying the exam rate
 * for one turns the leaderboard into a measure of how many times a student
 * pressed a button. The self-practice action is a separate, capped row.
 *
 * ⚠️ AND THE EVENT FIRES AGAIN ON EVERY REVISION. `ReviseGrade` re-runs
 * `FinalizeAttempt`, so one paper can be finalised several times with different
 * verdicts. The same rule as an attendance override (`SettleOnAttendanceOverridden`):
 * a revision that turns a pass into a fail takes the points back, and one that
 * turns it back into a pass returns them — through the chain, never a second
 * award, so the paper is paid exactly once or not at all however many times it
 * is revised. A re-fire that changes nothing is a no-op in both directions.
 */
class AwardOnAttemptFinalized implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly AwardPoints $award,
        private readonly AwardChain $chain,
        private readonly ReverseAward $reverse,
        private readonly ReinstateAward $reinstate,
    ) {}

    public function handle(AttemptFinalized $event): void
    {
        $attempt = $event->attempt;

        if ($attempt->is_practice) {
            return;
        }

        $original = $this->chain->original(
            (int) $attempt->student_user_id,
            'exam_passed',
            'attempt',
            (int) $attempt->getKey(),
        );

        if (! $attempt->passed) {
            if ($original !== null) {
                $this->reverse->handle($original);
            }

            return;
        }

        if ($original !== null) {
            $this->reinstate->handle($original);

            return;
        }

        $this->award->handle(new AwardRequest(
            studentUserId: (int) $attempt->student_user_id,
            actionKey: 'exam_passed',
            sourceType: 'attempt',
            sourceId: (int) $attempt->getKey(),
            workspaceId: (int) $attempt->workspace_id,
        ));
    }
}
