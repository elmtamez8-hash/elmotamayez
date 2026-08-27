<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationWriteBan;
use App\Modules\Community\Support\WriteBanReader;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionAttendanceDirectory;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * «لا تكتب هنا لعشر دقائق» — and lifting it again (FR-047).
 *
 * ⚠️ ROOMS ONLY, ON `SetConversationLock`'s REASONING AND FOR ITS REASON.
 * Silencing one person inside a private thread between them and their teacher is
 * a BAN — declared workspace-wide, recorded, appealable — and two ways to silence
 * somebody with only one of them audited is the one nobody reviews.
 *
 * ⚠️ AND A MODERATOR MAY NOT BE BANNED. The instrument exists so a teacher can
 * quiet one disruptive student for an hour; pointed at a colleague it is one
 * assistant locking another out of the room they are both running, from a button
 * that sits in the same row as every student's.
 */
class SetConversationWriteBan extends Action
{
    public function __construct(
        private readonly WriteBanReader $reader,
        private readonly CohortDirectory $cohorts,
        private readonly SessionAttendanceDirectory $seats,
        private readonly EnrollmentDirectory $enrollments,
    ) {}

    public function ban(
        Conversation $conversation,
        User $actor,
        string $userUuid,
        string $reason,
        ?int $minutes = null,
    ): ConversationWriteBan {
        if (! $conversation->kind->isPublic()) {
            throw new DomainException('لا يمكن إيقاف الكتابة في محادثةٍ خاصّة. لإيقاف شخصٍ في المساحة كلِّها استخدم الحظر.');
        }

        $target = $this->target($conversation, $userUuid);

        if ((int) $target->getKey() === (int) $actor->getKey()) {
            throw new DomainException('لا يمكنك إيقاف كتابتك أنت.');
        }

        return ConversationWriteBan::query()->create([
            'workspace_id' => (int) $conversation->workspace_id,
            'conversation_id' => (int) $conversation->getKey(),
            'user_id' => (int) $target->getKey(),
            'issued_by' => (int) $actor->getKey(),
            'reason' => $reason,
            /*
            | Null minutes means OPEN — lifted by hand, through `lift()` below.
            |
            | ⚠️ THE TEACHER'S SCREEN DOES NOT OFFER IT, THOUGH, AND THAT IS
            | DELIBERATE. Nothing in the product lifts a ban yet, so an option
            | with no exit would be a control whose only way out is a request
            | typed by hand. `SilenceControl` offers the four that lapse; the
            | capability stays here because `lift()` is what a moderation screen
            | will call, and removing it would be removing the reason to build one.
            */
            'expires_at' => $minutes === null ? null : now()->addMinutes($minutes),
        ]);
    }

    /**
     * Let them write again before the clock runs out.
     *
     * ⚠️ A STAMP, NEVER A DELETE. The row is the record that a moderator acted and
     * why — a deleted ban is a student who says they were silenced and a teacher
     * with nothing to show either way. `WriteBanReader` reads `lifted_at`.
     */
    public function lift(Conversation $conversation, User $actor, string $userUuid): void
    {
        $target = $this->target($conversation, $userUuid);

        $ban = $this->reader->activeBan((int) $target->getKey(), (int) $conversation->getKey());

        if ($ban === null) {
            return;
        }

        // Not fillable: lifting is this Action's decision, and mass-assignable the
        // pair becomes a second way to end a ban from outside it.
        $ban->forceFill(['lifted_at' => now(), 'lifted_by' => $actor->getKey()])->save();
    }

    /**
     * The person named, IF they belong in this room.
     *
     * ⚠️ THE ROOM IS PART OF THE QUESTION. A bare uuid with no membership check is
     * an identity probe: pass any user's and a `404` versus a `200` says whether
     * the account exists. It also stops a moderator banning somebody from a thread
     * they were never in — a row that reads as a disciplinary record of an event
     * that did not happen.
     */
    private function target(Conversation $conversation, string $userUuid): User
    {
        $user = User::query()->where('uuid', $userUuid)->first();

        if (! $user instanceof User || ! $this->belongsInRoom($user, $conversation)) {
            /*
            | ⚠️ THE SAME ANSWER FOR «NO SUCH ACCOUNT» AND «NOT IN THIS ROOM». A
            | distinct reply is an oracle telling whoever holds the moderation
            | button which uuids on the platform are real people.
            */
            throw new ModelNotFoundException('لم نجد هذا الحساب في هذا النقاش.');
        }

        return $user;
    }

    /**
     * Whether this person has any claim to be in this room.
     *
     * ⚠️ IT MIRRORS `ConversationPolicy::publicRoom()` ARM FOR ARM, and the first
     * version of this method did not — it asked the question for a cohort room
     * and returned `true` for every other kind. So a moderator could name ANY
     * uuid into a session room and write a disciplinary-looking row about
     * somebody who was never there, which is NFR-001أ's identity probe with a
     * record attached to it.
     *
     * A room attached to nothing has nobody who belongs in it, so it refuses —
     * the same direction `publicRoom()` refuses in, rather than the permissive
     * default that produced the hole.
     */
    private function belongsInRoom(User $user, Conversation $conversation): bool
    {
        if ($conversation->cohort_id !== null) {
            // «Was ever a member», not «is one now»: the person who moved out
            // last week can still write into the thread they may still read, and
            // is therefore still somebody a moderator may have to quiet.
            return $this->cohorts->wasEverMember($user, (int) $conversation->cohort_id);
        }

        if ($conversation->class_session_id !== null) {
            return $this->seats->hasSeatInSession($user, (int) $conversation->class_session_id);
        }

        if ($conversation->lesson_id !== null) {
            $courseId = Lesson::query()
                ->withoutWorkspaceScope()
                ->whereKey($conversation->lesson_id)
                ->value('course_id');

            return $courseId !== null && $this->enrollments->hasActiveEnrollment($user, (int) $courseId);
        }

        return false;
    }
}
