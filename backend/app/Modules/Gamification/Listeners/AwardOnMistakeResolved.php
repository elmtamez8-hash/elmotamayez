<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Assessments\Events\MistakeResolved;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A question they had got wrong, answered right ⇒ points.
 *
 * This event was raised by spec 008 with no listener at all, and its own docblock
 * says why: the moment lives inside the grading transaction, the hottest path in
 * that module, so 008 paid the one query then rather than making 009 edit the
 * code that marks every paper on the platform. This class is that bet paying off.
 *
 * The source is the QUESTION, not the answer row: a mistake on a given question
 * is fixed once. Keying on the answer would pay again every time the student
 * revisited it.
 */
class AwardOnMistakeResolved implements ShouldQueue
{
    public function __construct(private readonly AwardPoints $award) {}

    public function handle(MistakeResolved $event): void
    {
        $this->award->handle(new AwardRequest(
            studentUserId: $event->studentId,
            actionKey: 'mistake_resolved',
            sourceType: 'question',
            sourceId: $event->questionId,
            workspaceId: $event->workspaceId,
        ));
    }
}
