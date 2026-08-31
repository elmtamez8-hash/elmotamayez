<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Events;

/**
 * A participant answered the LAST question of a room's frozen set (FR-018).
 *
 * ⚠️ IT HAS A WRITER, AND THE WRITER IS THE ANSWER — NEVER THE CLOCK.
 * `AnswerStudyRoomQuestion` raises it at the moment `finished_at` is stamped,
 * because a state derived from `ends_at` passing fires nothing: there is no job
 * behind a room, by design. Hanging the award on the room closing would have made
 * `study_room_finished` a catalogue key with readers and no writer — the exact
 * shape `ClassSessionStatus::Interrupted` had, where three readers agreed a
 * requirement was implemented and nothing could ever produce it.
 *
 * ⚠️ AND «RAN OUT OF TIME AT EIGHT OF TEN» IS NOT FINISHED. That participant sees
 * their score, keeps every answer, and earns no points — the event is about
 * completing the set, so a room that merely ended raises nothing.
 *
 * Ids rather than models, on `ConceptMastered`'s precedent.
 */
class StudyRoomFinished
{
    public function __construct(
        public readonly int $studentId,
        public readonly int $roomId,
        public readonly int $workspaceId,
        public readonly int $participantId,
    ) {}
}
