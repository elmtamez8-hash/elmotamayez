<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Listeners;

use App\Modules\Assessments\Events\StudyRoomFinished;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A study room's paper finished ⇒ points and coins (spec 012 · FR-018).
 *
 * ⚠️ THE SOURCE IS THE ROOM, NOT THE PARTICIPATION ROW. `award_entries` is keyed
 * on `(student, action_key, source_type, source_id, reversal_of_id)`, so the
 * room id is what makes «one award per room per person» a database fact — and a
 * participation id would key it on a row that is already unique per person
 * anyway, which is the same guarantee spelled less obviously.
 *
 * Coins are real here, unlike `focus_session` and `invite_friend`: a room lives
 * inside ONE teacher's bank, so there is a workspace to hold them — `AwardPoints`
 * throws on a coin-bearing action with a null workspace rather than guessing a
 * purse.
 */
class AwardOnStudyRoomFinished implements ShouldQueue
{
    public function __construct(private readonly AwardPoints $award) {}

    public function handle(StudyRoomFinished $event): void
    {
        $this->award->handle(new AwardRequest(
            studentUserId: $event->studentId,
            actionKey: 'study_room_finished',
            sourceType: 'study_room',
            sourceId: $event->roomId,
            workspaceId: $event->workspaceId,
        ));
    }
}
