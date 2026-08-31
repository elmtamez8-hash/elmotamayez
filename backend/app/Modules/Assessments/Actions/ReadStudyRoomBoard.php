<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Models\StudyRoom;
use App\Modules\Assessments\Models\StudyRoomParticipant;
use App\Shared\Actions\Action;

/**
 * The scoreboard of one room, in one query (FR-014).
 *
 * ⚠️ THE EAGER LOAD NAMES `first_name` AND `last_name`, NEVER `name`. `users` has
 * no `name` column at all — it is an accessor over the two — so
 * `->with('user:id,uuid,name')` selects a column that does not exist and every row
 * renders as an EMPTY STRING. That exact spelling shipped in six places across
 * four modules before it was found by hand, and the board is the worst of them:
 * it is PUSHED to every subscriber, so there is no screen on which one person
 * notices «» first and says so.
 *
 * ⚠️ AND IT IS BULK BY CONSTRUCTION. A Resource runs once per row, so a lookup
 * per participant is the `ClassSessionResource` N+1 reached from a new direction —
 * inside the hottest write path in the phase, since every answer rebuilds this.
 */
class ReadStudyRoomBoard extends Action
{
    /**
     * @return list<array{uuid: string, name: string, score: int, answered: int}>
     */
    public function handle(StudyRoom $room): array
    {
        $participants = StudyRoomParticipant::query()
            ->withoutWorkspaceScope()
            ->where('study_room_id', $room->getKey())
            // `index(study_room_id, score)` is what makes this one ordered read.
            ->orderByDesc('score')
            ->orderBy('joined_at')
            ->with('user:id,uuid,first_name,last_name')
            ->get(['id', 'uuid', 'study_room_id', 'user_id', 'score', 'answered_count', 'joined_at']);

        $rows = [];

        foreach ($participants as $participant) {
            $rows[] = [
                // ⚠️ The PARTICIPANT's uuid. The user's is a platform-wide
                // identifier for somebody who may be a child, and this list is
                // handed to their peers.
                'uuid' => (string) $participant->uuid,
                'name' => (string) $participant->user?->name,
                'score' => (int) $participant->score,
                'answered' => (int) $participant->answered_count,
            ];
        }

        return $rows;
    }
}
