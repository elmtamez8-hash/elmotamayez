<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Community\Support\BanReader;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspacePermission;
use Illuminate\Auth\Access\Response;

/**
 * Who may hide a message and ban a participant (`FR-021`).
 *
 * ⚠️ THE TEACHER **AND WHOEVER THEY DELEGATED IT TO**, which is why this is a
 * permission and not an owner check. A rule reading «the teacher alone» would
 * make the one person who cannot watch the room all day the only person who can
 * act in it — and FR-021 says «ومن فُوِّض» in as many words.
 *
 * ⚠️ AND IT IS `chat.moderate`, NOT `chat.reply`. Answering students and silencing
 * them are different powers over the same people; one constant for both would
 * make every assistant who may reply a moderator too, with no way for an owner to
 * separate them. Both live on `$teacher` in `RolePermissionMatrix`, and each is
 * ticked onto a named assistant deliberately.
 *
 * ⚠️ AND THE WORKSPACE IS PASSED IN, never read from the ambient context. The
 * subject's own workspace is what the decision belongs to — a moderator whose
 * context points elsewhere must not act on a room they do not moderate, and
 * `WorkspaceContext::id()` falls back to `users.last_workspace_id` for everybody.
 */
class ModerationActionPolicy
{
    public function __construct(
        private readonly AssistantScopeDirectory $assistants,
        private readonly BanReader $bans,
    ) {}

    public function moderate(User $user, int $workspaceId): Response
    {
        $isMember = $user->workspaces()
            ->withoutGlobalScopes()
            ->whereKey($workspaceId)
            ->exists();

        if (! $isMember) {
            return Response::deny('لا تملك صلاحيّة الإشراف هنا.');
        }

        // Asked of THIS workspace, not the reader's current one (scan F8).
        return WorkspacePermission::holds($user, $workspaceId, Permissions::CHAT_MODERATE)
            ? Response::allow()
            : Response::deny('لا تملك صلاحيّة الإشراف على الشات.');
    }

    /**
     * Banning a PERSON across the workspace, and lifting it (`subject_type = user`).
     *
     * ⛔ SPEC 010 · FR-005 — A CONFINED ASSISTANT BANS ONLY A STUDENT OF THEIR OWN
     * COURSES. The ban has no room to ask about: it silences the person in every
     * thread of the workspace, so until 2026-09-29 an assistant confined to one
     * course could silence a student of any other course (or another member of
     * staff) with `moderate()` alone. `mayActOnStudent()` is the question
     * `teacherSide()` asks of a private thread — «is this person enrolled in a
     * course inside my scope» — and it is the right one here for the same
     * reason: a person names no course.
     *
     * Anyone who is not a student of the scope is refused, which is deliberate:
     * a colleague, a prospect with no enrolment and a student of a far course are
     * the teacher's call. A teacher, an owner and an unconfined assistant pass the
     * scope as a no-op. The lift has its own door, `liftBan()`.
     */
    public function banPerson(User $user, int $workspaceId, int $subjectUserId): Response
    {
        $moderate = $this->moderate($user, $workspaceId);

        if ($moderate->denied()) {
            return $moderate;
        }

        return $this->assistants->mayActOnStudent($user, $workspaceId, $subjectUserId)
            ? Response::allow()
            : Response::deny('هذا الطالب خارج نطاق عملك.');
    }

    /**
     * Lifting a person's workspace ban (owner decision 2026-09-29).
     *
     * ⚠️ A CONFINED ASSISTANT LIFTS THE BANS THEY PLACED — ALL OF THEM, AND NO
     * OTHERS. Asking `banPerson()` here had two faults: an assistant could undo a
     * ban the teacher placed on a student of their course, and could NOT undo
     * their own ban once that student's enrolment ended (they are then no student
     * of the scope, so `mayActOnStudent()` refuses). Authorship answers both, and
     * it needs no scope question at all. With no live ban there is nothing to
     * undo, and the ban's own door is asked, so a lift never reaches further than
     * a ban. A teacher, an owner and an unconfined assistant lift any ban.
     */
    public function liftBan(User $user, int $workspaceId, int $subjectUserId): Response
    {
        $moderate = $this->moderate($user, $workspaceId);

        if ($moderate->denied()) {
            return $moderate;
        }

        if ($this->assistants->scopedCourseIdsFor($user, $workspaceId) === null) {
            return Response::allow();
        }

        $placedBy = $this->bans->liveBanActorId($subjectUserId, $workspaceId);

        if ($placedBy === null) {
            return $this->banPerson($user, $workspaceId, $subjectUserId);
        }

        return $placedBy === (int) $user->getKey()
            ? Response::allow()
            : Response::deny('لا يرفع هذا المنع إلا من وضعه أو المعلّم.');
    }
}
