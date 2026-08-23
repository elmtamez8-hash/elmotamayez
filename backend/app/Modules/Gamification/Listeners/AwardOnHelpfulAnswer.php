<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Community\Events\HelpfulAnswerMarked;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A teacher endorsed a student's answer in a public room ⇒ points (010 · FR-023).
 *
 * ⚠️ THE SOURCE IS THE MESSAGE, AND THAT IS WHAT MAKES IT PAY ONCE. The award key
 * is `(student, action, source_type, source_id)`, so a second endorsement of the
 * SAME message is ignored while a different message pays again — which is the
 * behaviour the requirement describes. Community's own conditional update means
 * this listener is not reached a second time at all; the key is the layer under
 * it, not instead of it.
 */
class AwardOnHelpfulAnswer implements ShouldQueue
{
    public function __construct(private readonly AwardPoints $award) {}

    public function handle(HelpfulAnswerMarked $event): void
    {
        $this->award->handle(new AwardRequest(
            studentUserId: $event->studentUserId,
            actionKey: 'helpful_answer',
            sourceType: $event->sourceType,
            sourceId: $event->sourceId,
            workspaceId: $event->workspaceId,
        ));
    }
}
