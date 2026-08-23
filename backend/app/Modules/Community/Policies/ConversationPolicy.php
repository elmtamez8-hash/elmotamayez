<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Community\Enums\ConversationKind;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Auth\Access\Response;

/**
 * Who may read a conversation, and — separately — who may write into it.
 *
 * ⚠️ READING AND WRITING ARE TWO ABILITIES AND MUST STAY SO (`FR-014`). When a
 * student's relationship with a teacher ends the sending stops and the archive
 * remains readable; one ability used for both doors makes that impossible to
 * express, and the conversation a student paid for their lessons in disappears
 * on the day their enrolment completes.
 *
 * ⚠️ AND THIS CLASS IS ALSO THE CHANNEL AUTHORISER. `routes/channels.php` calls
 * `view()` here rather than restating the condition beside it — two spellings of
 * "may this person read this conversation" put one answer on the screen and
 * another at the door, the defect already recorded for `BookingEligibility` and
 * for `ListLeaderboardScopes`.
 */
class ConversationPolicy
{
    public function __construct(
        private readonly AssistantScopeDirectory $assistants,
        private readonly EnrollmentDirectory $enrollments,
    ) {}

    /** May this person read the thread at all? */
    public function view(User $user, Conversation $conversation): Response
    {
        if ($conversation->kind !== ConversationKind::Private) {
            // The public rooms arrive with US3, where entitlement to the session
            // or the lesson is the question. Refusing until then is deliberate:
            // a kind with no guard written yet must not fall through to "yes".
            return Response::deny('غرف الحصص والدروس لم تُفعَّل بعد.');
        }

        if ($this->isTheStudent($user, $conversation)) {
            return Response::allow();
        }

        return $this->teacherSide($user, $conversation);
    }

    /**
     * May this person send into it right now?
     *
     * Reading first, then the relationship — the archive is readable for ever and
     * the send is not.
     */
    public function post(User $user, Conversation $conversation): Response
    {
        $view = $this->view($user, $conversation);

        if ($view->denied()) {
            return $view;
        }

        $studentId = (int) $conversation->student_user_id;
        $student = User::query()->find($studentId);

        if (! $student instanceof User) {
            return Response::deny('لم يعد الطرف الآخر متاحاً.');
        }

        /*
        | ⚠️ THE SAME CONDITION FOR BOTH SIDES. A rule that only stopped the
        | student writing would bind the person with less power in the
        | relationship and leave the teacher messaging someone who has left.
        */
        return $this->enrollments->hasActiveEnrollmentInWorkspace($student, (int) $conversation->workspace_id)
            ? Response::allow()
            : Response::deny('انتهت علاقتك التعليميّة هنا، والمحادثة صارت للقراءة فقط.');
    }

    private function isTheStudent(User $user, Conversation $conversation): bool
    {
        if ((int) $conversation->student_user_id === (int) $user->getKey()) {
            return true;
        }

        // The explicit participant row, which is what a student's own list is
        // built from. Asked second because the column above answers without a
        // query in the ordinary case.
        return ConversationParticipant::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
    }

    /**
     * The teacher, an assistant, or nobody.
     *
     * ⚠️ MEMBERSHIP **AND** THE PERMISSION **AND** THE SCOPE, all three. A
     * student is a member of no workspace, so membership alone separates the two
     * sides; `chat.reply` is what an owner ticks on for one named assistant; and
     * `mayActOnStudent()` is the confinement — a private conversation names no
     * course, so an assistant restricted to one course would otherwise read every
     * private conversation in the workspace.
     */
    private function teacherSide(User $user, Conversation $conversation): Response
    {
        $workspaceId = (int) $conversation->workspace_id;

        $isMember = $user->workspaces()
            ->withoutGlobalScopes()
            ->whereKey($workspaceId)
            ->exists();

        if (! $isMember) {
            return Response::deny('لست طرفاً في هذه المحادثة.');
        }

        if (! $user->hasPermissionTo(Permissions::CHAT_REPLY)) {
            return Response::deny('لا تملك صلاحيّة الاطّلاع على رسائل الطلاب.');
        }

        return $this->assistants->mayActOnStudent($user, $workspaceId, (int) $conversation->student_user_id)
            ? Response::allow()
            : Response::deny('هذا الطالب خارج نطاق عملك.');
    }
}
