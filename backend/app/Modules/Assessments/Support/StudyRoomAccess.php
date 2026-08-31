<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Modules\Assessments\Models\StudyRoom;
use App\Modules\Assessments\Models\StudyRoomParticipant;
use Illuminate\Support\Facades\DB;

/**
 * Who may open a study room's door, and who is already inside it (FR-017).
 *
 * ⚠️ READ-ONLY, AND THAT IS WHY IT IS A SUPPORT CLASS RATHER THAN PART OF THE
 * ACTION. `JoinStudyRoom` is a write with one `handle()`, while every entry in
 * `routes/channels.php` needs a READ of the same question — and two conditions
 * written side by side put one answer on the screen and another at the door. This
 * repository has already paid for that twice, with `BookingEligibility`'s host
 * check and with `ListLeaderboardScopes`.
 *
 * ⚠️ AND THE TWO QUESTIONS IT ANSWERS ARE NOT ONE QUESTION. «May join» opens the
 * door; «is a participant» is what the live board is authorised on. Guarding the
 * channel with the first would let any eligible student holding the uuid read
 * every name and score in the room without joining it and without appearing to
 * anyone.
 */
class StudyRoomAccess
{
    public function __construct(private readonly PracticePool $pool) {}

    /**
     * The room behind this uuid, or null.
     *
     * ⚠️ `withoutWorkspaceScope()`, AND NOT AS AN OPTIMISATION. A student is a
     * member of no workspace, so `WorkspaceContext::id()` is null and the scope
     * adds no condition anyway — leaning on it would be leaning on nothing. The
     * guard is whatever the caller asks next, never this lookup. The same
     * reasoning `channels.php` writes down for `Conversation`.
     */
    public function room(string $uuid): ?StudyRoom
    {
        return StudyRoom::query()->withoutWorkspaceScope()->where('uuid', $uuid)->first();
    }

    /**
     * May this student answer the paper this room froze?
     *
     * ⚠️ THE PREDICATE IS «MY OWN POOL CONTAINS EVERY QUESTION IN THIS ROOM»,
     * NEVER «I HAVE AN ACTIVE ENROLMENT IN THIS WORKSPACE». That weaker form
     * opens two holes at once, and both are FR-006 undone from a new direction:
     *
     * 1. `PracticePool::withheldQuestionIds()` is computed PER STUDENT. A host
     *    who has already sat a published exam legitimately freezes its questions
     *    into their room — and every joiner who has NOT sat it answers them and
     *    is handed the correct option ids and the explanation on the spot. That
     *    is next week's paper with its answers, which is precisely what the pool
     *    exists to prevent.
     * 2. `questionsFor()` is bounded by the student's own COURSES, not by the
     *    workspace. Without this, a student enrolled in «العربي» joins a room
     *    built from the «الفيزياء» bank at the same teacher. `EnrollmentDirectory`
     *    already recorded that workspace membership «is no longer enough alone».
     *
     * FR-017 says «its QUESTIONS», not «its workspace».
     *
     * ⚠️ ONE QUERY, AND `question_count` IS WHAT MAKES IT ONE. That column holds
     * what was actually DELIVERED at creation, so the count of frozen questions
     * this student may reach is compared against it directly rather than fetching
     * the id list first and counting it in PHP.
     */
    public function mayJoin(User $student, StudyRoom $room): bool
    {
        $frozen = (int) $room->question_count;

        // A room with no paper cannot be joined by anybody. `CreateStudyRoom`
        // refuses at zero, so this is a corrupted row rather than a normal case —
        // and returning true for it would admit everyone on the platform.
        if ($frozen < 1) {
            return false;
        }

        $reachable = $this->pool
            ->questionsFor((int) $room->workspace_id, $student)
            ->whereIn('id', DB::table('study_room_questions')
                ->select('question_id')
                ->where('study_room_id', $room->getKey()))
            ->count();

        return $reachable === $frozen;
    }

    /**
     * Is this person already inside — the question the broadcast channel asks.
     */
    public function participant(User $user, StudyRoom $room): ?StudyRoomParticipant
    {
        return StudyRoomParticipant::query()
            ->withoutWorkspaceScope()
            ->where('study_room_id', $room->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }
}
