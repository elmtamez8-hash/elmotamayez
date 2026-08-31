<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Exceptions\StudyRoomRefusal;
use App\Modules\Assessments\Exceptions\StudyRoomSeatTaken;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\StudyRoom;
use App\Modules\Assessments\Models\StudyRoomParticipant;
use App\Modules\Assessments\Models\StudyRoomQuestion;
use App\Modules\Assessments\Support\PracticePaper;
use App\Modules\Assessments\Support\StudyRoomAccess;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Take a seat in a study room, or come back to the one already held (FR-015).
 *
 * ⚠️ RETURNING TO A ROOM IS THIS SAME CALL, AND IT IS A READ. The resume is a row
 * — `answered_count`, and the answers already in `exam_answers` under this
 * participant's own attempt — so a student whose connection dropped opens the
 * page again and carries on. Nothing lives in the browser, which is what SC-007
 * measures.
 */
class JoinStudyRoom extends Action
{
    public function __construct(
        private readonly StudyRoomAccess $access,
        private readonly PracticePaper $paper,
    ) {}

    /**
     * @return array{room: StudyRoom, participant: StudyRoomParticipant, resumed: bool}
     *
     * @throws ModelNotFoundException when the uuid names no room
     * @throws StudyRoomRefusal closed, full, or not this student's paper
     * @throws RuntimeException when the claim was lost and no winner exists
     */
    public function handle(User $student, string $uuid): array
    {
        $room = $this->access->room($uuid);

        if ($room === null) {
            throw (new ModelNotFoundException)->setModel(StudyRoom::class);
        }

        // FR-016, before anything else: a closed room refuses a new joiner and a
        // returning one alike, and neither costs a write.
        if (! $room->state()->isOpen()) {
            throw StudyRoomRefusal::closed();
        }

        $held = $this->access->participant($student, $room);

        if ($held !== null) {
            return ['room' => $room, 'participant' => $held, 'resumed' => true];
        }

        if (! $this->access->mayJoin($student, $room)) {
            throw StudyRoomRefusal::notEligible();
        }

        try {
            return ['room' => $room, 'participant' => $this->seat($room, $student), 'resumed' => false];
        } catch (StudyRoomSeatTaken $taken) {
            /*
            | A concurrent join won the unique index. Our transaction has rolled
            | back — which is the whole reason it throws rather than returning the
            | winner's row from inside it — so the seat is re-read out here and
            | answered as a resume, exactly as a reload would be.
            */
            $winner = $this->access->participant($student, $room);

            if ($winner === null) {
                throw new RuntimeException(
                    "Study room seat was lost and no winner exists (room {$room->getKey()}, user {$student->getKey()}).",
                    0,
                    $taken,
                );
            }

            return ['room' => $room, 'participant' => $winner, 'resumed' => true];
        }
    }

    /**
     * One transaction: the attempt, the claim, then the paper.
     *
     * ⚠️ THE CLAIM COMES BEFORE THE N ITEMS, AND EVERYTHING IS ONE TRANSACTION.
     * Written the other way round, a joiner who loses the capacity race leaves an
     * orphan attempt and one item per question behind it — no participant, no
     * sweep, and nothing that would ever notice. The attempt row itself has to
     * exist first because `study_room_participants.attempt_id` is NOT NULL; it is
     * one insert, and a loser's rollback takes it with everything else.
     *
     * ⚠️ AND CAPACITY IS CLAIMED, NOT COUNTED-THEN-INSERTED. `count()` followed by
     * `insert()` IS the race by definition, and `lockForUpdate()` is a documented
     * no-op on the SQLite every test here runs against. The row goes in and then
     * asks its own RANK — how many rows in this room were written at or before it
     * — which is a fact about a row that already exists. Two racers that both
     * commit can still over-admit by one, and that is accepted deliberately: this
     * cap bounds broadcast amplification, not entitlement and not money, so
     * «eleven in a room of ten» costs one extra row on a board. A seat in a paid
     * class is never claimed this way.
     */
    private function seat(StudyRoom $room, User $student): StudyRoomParticipant
    {
        return DB::transaction(function () use ($room, $student): StudyRoomParticipant {
            $attempt = $this->paper->write((int) $room->workspace_id, $student, collect());

            $now = now();

            $written = StudyRoomParticipant::query()->insertOrIgnore([
                // ⚠️ A RAW INSERT BOOTS NO MODEL, so `HasUuid` never fires — and
                // on MySQL the resulting NOT NULL violation is downgraded to a
                // warning and `''` is stored, after which every later participant
                // on the platform collides with that row on `unique(uuid)` and is
                // silently read as a duplicate.
                'uuid' => (string) Str::orderedUuid(),
                'workspace_id' => $room->workspace_id,
                'study_room_id' => $room->getKey(),
                'user_id' => $student->getKey(),
                'attempt_id' => $attempt->getKey(),
                'score' => 0,
                'answered_count' => 0,
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /*
            | ⚠️ ZERO ROWS THROWS, IT DOES NOT RETURN THE WINNER'S ROW. Returning
            | from inside `DB::transaction()` COMMITS — and this call has already
            | written an attempt, so the loser would leave an orphan `in_progress`
            | paper with no participant pointing at it, no sweep and nothing that
            | would notice. Throwing unwinds it; the caller re-reads outside.
            |
            | SQLite cannot produce this race at all, which is why the reasoning
            | is written here rather than left to a test to find.
            */
            if ($written === 0) {
                throw new StudyRoomSeatTaken;
            }

            $participant = StudyRoomParticipant::query()
                ->withoutWorkspaceScope()
                ->where('study_room_id', $room->getKey())
                ->where('user_id', $student->getKey())
                ->first();

            /*
            | A row was written, so a miss here is not a duplicate — it is a write
            | MySQL downgraded to a warning. That throws rather than reporting a
            | success nobody can use.
            */
            if ($participant === null) {
                throw new RuntimeException(
                    "Study room participant insert wrote no row (room {$room->getKey()}, user {$student->getKey()})."
                );
            }

            $rank = StudyRoomParticipant::query()
                ->withoutWorkspaceScope()
                ->where('study_room_id', $room->getKey())
                ->where('id', '<=', $participant->getKey())
                ->count();

            if ($rank > (int) $room->max_participants) {
                throw StudyRoomRefusal::full();
            }

            $this->copyFrozenPaper($room, (int) $attempt->getKey());

            return $participant;
        });
    }

    /**
     * The room's frozen paper, copied into this participant's attempt.
     *
     * ⚠️ FROM THE SNAPSHOT, NEVER FROM THE LIVE QUESTION — which is exactly why
     * `PracticePaper::write()` is not reused for it. That method builds each
     * snapshot with `QuestionSnapshot::of($question)`, i.e. from the row as it
     * stands NOW, and the whole point of a room is that everyone inside answers
     * the paper as it was frozen. A question edited after the room opened would
     * otherwise reach the second joiner in a different form from the first.
     *
     * ⚠️ AND THE TIMESTAMPS ARE EXPLICIT. `attempt_items` is swept by age and a
     * bulk `insert()` boots no model: a null `created_at` matches no age
     * predicate, and the row never expires.
     */
    private function copyFrozenPaper(StudyRoom $room, int $attemptId): void
    {
        $now = now();
        $rows = [];

        $frozen = StudyRoomQuestion::query()
            ->withoutWorkspaceScope()
            ->where('study_room_id', $room->getKey())
            ->orderBy('order')
            ->get();

        foreach ($frozen as $question) {
            $rows[] = [
                'workspace_id' => $room->workspace_id,
                'attempt_id' => $attemptId,
                'question_id' => $question->question_id,
                'order' => $question->order,
                'points' => $question->points,
                'snapshot' => json_encode($question->snapshot),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            AttemptItem::query()->insert($rows);
        }
    }
}
