<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Assessments\Events\AttemptFinalized;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A paper is marked and passed ⇒ points.
 *
 * ⚠️ PRACTICE ATTEMPTS EARN NOTHING HERE. `AttemptFinalized` fires for them too
 * (its own docblock says so, and spec 009 is the reason it does) — but a practice
 * run is unlimited, instantly markable and self-chosen, so paying the exam rate
 * for one turns the leaderboard into a measure of how many times a student
 * pressed a button. The self-practice action is a separate, capped row.
 */
class AwardOnAttemptFinalized implements ShouldQueue
{
    public function __construct(private readonly AwardPoints $award) {}

    public function handle(AttemptFinalized $event): void
    {
        $attempt = $event->attempt;

        if ($attempt->is_practice || ! $attempt->passed) {
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
