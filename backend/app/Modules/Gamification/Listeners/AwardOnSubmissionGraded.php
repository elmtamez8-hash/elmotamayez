<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Assessments\Events\SubmissionGraded;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Homework handed in and marked ⇒ points.
 *
 * ⚠️ ON `SubmissionGraded` AND NOT ON `AssignmentSubmitted`, which fires earlier
 * and would be the obvious choice. Paying at submission pays for a blank page:
 * the student uploads anything, collects the points and never comes back. The
 * mark is what makes the hand-in real, and the late party in the gap is the
 * teacher, not the student — so nothing about this costs the student time.
 */
class AwardOnSubmissionGraded implements ShouldQueue
{
    public function __construct(private readonly AwardPoints $award) {}

    public function handle(SubmissionGraded $event): void
    {
        $submission = $event->submission;

        $this->award->handle(new AwardRequest(
            studentUserId: (int) $submission->student_user_id,
            actionKey: 'homework_submitted',
            sourceType: 'submission',
            sourceId: (int) $submission->getKey(),
            workspaceId: (int) $submission->workspace_id,
        ));
    }
}
