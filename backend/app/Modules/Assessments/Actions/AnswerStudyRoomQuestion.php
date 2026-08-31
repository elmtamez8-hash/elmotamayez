<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Events\StudyRoomBoardUpdated;
use App\Modules\Assessments\Events\StudyRoomFinished;
use App\Modules\Assessments\Exceptions\AdaptiveConflictException;
use App\Modules\Assessments\Exceptions\StudyRoomRefusal;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\StudyRoom;
use App\Modules\Assessments\Models\StudyRoomParticipant;
use App\Modules\Assessments\Support\AdaptiveSeal;
use App\Modules\Assessments\Support\AnswerMarker;
use App\Modules\Assessments\Support\StudyRoomAccess;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

/**
 * Mark one answer inside a study room and move the board (FR-014 · FR-018).
 *
 * ⚠️ IT GOES THROUGH `AnswerMarker`, THE SAME ONE THE EXAM AND THE ADAPTIVE PATH
 * USE. That class was extracted rather than copied precisely so a third caller
 * would not grow a third answer to «is this right, and does getting it right FIX
 * something» — the mistake notebook is derived from `exam_answers` alone, so an
 * answer written any other way is invisible to it, and one written twice
 * double-counts every mistake in it.
 *
 * ⚠️ AND `finished_at` IS STAMPED BY THE ANSWER THAT COMPLETES THE SET, NEVER BY
 * THE ROOM CLOSING. A derived state raises no event: there is no job behind a
 * room by design, so hanging the award on `ends_at` passing would make
 * `study_room_finished` a catalogue key with readers and no writer — the shape
 * `ClassSessionStatus::Interrupted` had. Somebody who runs out of time at eight
 * of ten sees their score, keeps every answer, and earns nothing.
 */
class AnswerStudyRoomQuestion extends Action
{
    /**
     * ⚠️ ONE BROADCAST A SECOND PER ROOM, AND THE LAST ONE IS NEVER DROPPED.
     * SC-006 asks for two seconds at p95, and thirty people answering thirty
     * questions is nine hundred frames to thirty subscribers if nothing throttles
     * it. But a naive gate drops the TRAILING update — and with no later answer to
     * flush it, every screen in the room would show a stale board for ever, since
     * the HTTP board is only the no-socket fallback. So the finishing answer, and
     * every answer that completes somebody's paper, publishes unconditionally.
     */
    private const BROADCAST_EVERY_SECONDS = 1;

    public function __construct(
        private readonly StudyRoomAccess $access,
        private readonly AnswerMarker $marker,
        private readonly ReadStudyRoomBoard $board,
        private readonly AdaptiveSeal $seal,
    ) {}

    /**
     * @param  list<int>  $optionIds
     * @return array{
     *     room: StudyRoom,
     *     participant: StudyRoomParticipant,
     *     is_correct: bool,
     *     correct_option_ids: list<int>,
     *     explanation: string|null,
     *     finished: bool,
     * }
     *
     * @throws ModelNotFoundException the room, or a question never put to this person
     * @throws StudyRoomRefusal closed, or the reader is not in this room
     * @throws AdaptiveConflictException this question already carries an answer
     */
    public function handle(User $student, string $uuid, int $questionId, array $optionIds): array
    {
        $room = $this->access->room($uuid);

        if ($room === null) {
            throw (new ModelNotFoundException)->setModel(StudyRoom::class);
        }

        $participant = $this->access->participant($student, $room);

        /*
        | ⚠️ THE PARTICIPATION ROW IS THE GUARD, NOT ELIGIBILITY. «May join» and
        | «is inside» are two questions: answering on the first would let anyone
        | holding the uuid write into a room they never entered and appear on its
        | board out of nowhere.
        */
        if ($participant === null) {
            throw StudyRoomRefusal::notEligible();
        }

        // FR-016: the room's hour is over, derived from the clock.
        if (! $room->state()->isOpen()) {
            throw StudyRoomRefusal::closed();
        }

        $item = AttemptItem::query()
            ->withoutWorkspaceScope()
            ->where('attempt_id', $participant->attempt_id)
            ->where('question_id', $questionId)
            ->first();

        /*
        | ⚠️ 404 AND NOT 422. A question id that was never put to this participant
        | is a question about somebody else's paper, and «that is not in your
        | room» answered for an id that exists elsewhere is a probe telling the
        | caller which ids do.
        */
        if ($item === null) {
            throw (new ModelNotFoundException)->setModel(AttemptItem::class);
        }

        $previouslyWrong = $this->marker->previouslyWrongQuestionIds($participant->paper(), [$questionId]);

        try {
            $correct = $this->marker->mark($participant->paper(), $item, $optionIds, null, $previouslyWrong);
        } catch (DomainException $exception) {
            // The marker's only `DomainException` is the duplicate, and a second
            // tap is a conflict rather than a bad request.
            throw new AdaptiveConflictException($exception->getMessage(), 0, $exception);
        }

        $finished = $this->advance($room, $participant, $correct ? (int) $item->points : 0);

        $snapshot = $item->snapshot;

        return [
            'room' => $room,
            'participant' => $participant->refresh(),
            'is_correct' => $correct,
            // The mark scheme reaches the student HERE, one request after the
            // question — never inside the payload that asked it.
            'correct_option_ids' => $item->correctOptionIds(),
            'explanation' => is_string($snapshot['explanation'] ?? null) ? $snapshot['explanation'] : null,
            'finished' => $finished,
        ];
    }

    /**
     * Move the counters, close the paper if that was the last one, publish.
     *
     * ⚠️ THE COUNTERS MOVE ONLY AFTER THE ANSWER ROW IS WRITTEN. The insert is the
     * point of serialisation — `unique(attempt_id, question_id)` is what turns a
     * double tap into a refusal rather than a second scored answer — so a score
     * advanced before it is advanced again by the tap that gets refused.
     *
     * ⚠️ AND BOTH COLUMNS MOVE IN SQL, NEVER AS `$row->x + $n` WRITTEN BACK. Two
     * answers in flight for one participant is unusual but not impossible (two
     * tabs, two questions), and reading-then-writing lets both compute the same
     * number — a score that silently loses one answer, on a board everybody in
     * the room is watching.
     */
    private function advance(StudyRoom $room, StudyRoomParticipant $participant, int $points): bool
    {
        StudyRoomParticipant::query()
            ->withoutWorkspaceScope()
            ->whereKey($participant->getKey())
            ->incrementEach(['answered_count' => 1, 'score' => $points], ['updated_at' => now()]);

        $participant->refresh();

        $finished = false;

        /*
        | ⚠️ THE CLAIM IS A CONDITIONAL UPDATE, so the event fires exactly once
        | however many requests decide this was the last question. A read followed
        | by a write would award `study_room_finished` twice for one paper.
        */
        if ($participant->answered_count >= (int) $room->question_count && $participant->finished_at === null) {
            $finished = StudyRoomParticipant::query()
                ->withoutWorkspaceScope()
                ->whereKey($participant->getKey())
                ->whereNull('finished_at')
                ->update(['finished_at' => now()]) === 1;

            if ($finished) {
                // The fourth ending. A paper left `in_progress` reads as still
                // open to the grade directory and to the mistake notebook.
                $this->seal->sealAttempt((int) $participant->attempt_id);

                event(new StudyRoomFinished(
                    (int) $participant->user_id,
                    (int) $room->getKey(),
                    (int) $room->workspace_id,
                    (int) $participant->getKey(),
                ));
            }
        }

        $this->publish($room, $finished);

        return $finished;
    }

    /**
     * Push the board, at most once a second per room — and always on a finish.
     *
     * `Cache::add()` is the gate: it writes only if the key is absent, which is
     * one atomic operation rather than a read and a write somebody else can slip
     * between.
     */
    private function publish(StudyRoom $room, bool $force): void
    {
        $gate = 'study-room-board:'.$room->getKey();

        if (! $force && ! Cache::add($gate, 1, self::BROADCAST_EVERY_SECONDS)) {
            return;
        }

        event(new StudyRoomBoardUpdated(
            (string) $room->uuid,
            $room->ends_at->toIso8601String(),
            $this->board->handle($room),
        ));
    }
}
