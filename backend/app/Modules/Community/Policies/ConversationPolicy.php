<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Community\Support\BanReader;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionAttendanceDirectory;
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
        private readonly SessionAttendanceDirectory $seats,
        private readonly BanReader $bans,
    ) {}

    /** May this person read the thread at all? */
    public function view(User $user, Conversation $conversation): Response
    {
        if ($conversation->kind->isPublic()) {
            return $this->publicRoom($user, $conversation);
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

        /*
        | ⚠️ THE BAN IS ASKED ON EVERY KIND, AND BEFORE ANYTHING ELSE. It is
        | declared workspace-wide (`FR-022`), so a check that lived in the session
        | room alone would move the argument into the private chat inside a
        | minute. `StartConversation` asks the same reader, because a banned
        | person opening a fresh thread is the conversation the ban was about.
        */
        if ($this->bans->isBanned((int) $user->getKey(), (int) $conversation->workspace_id)) {
            return Response::deny('تم إيقاف الكتابة عن حسابك في هذه المساحة. يمكنك القراءة.');
        }

        if ($conversation->kind->isPublic()) {
            /*
            | A room has no enrolment to end: entitlement to be in it IS the seat
            | or the membership, and `view()` has just asked. The FR-014 rule
            | below is about a RELATIONSHIP with one teacher, which a room does
            | not have — applying it here would close the chat under a lesson to
            | everyone whose course finished, including the seat holders.
            */
            return Response::allow();
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

    /**
     * The room under a session or a lesson (`FR-017` · `FR-018`).
     *
     * ⚠️ THE SEAT, NOT THE ENROLMENT. A student who studies with this teacher and
     * could book this very session has not booked it — the room is for the people
     * in the lesson, not for everyone who might one day join. For a lesson room
     * the available contract read is enrolment in its course, which is COARSER
     * than `Enrollment::accessTo()`: a student who has not reached a locked lesson
     * can still read the questions under it. Deliberate — asking `accessTo()`
     * would mean Community loading Learning's models, and a chat under a lesson
     * nobody has opened yet is empty anyway.
     */
    private function publicRoom(User $user, Conversation $conversation): Response
    {
        $workspaceId = (int) $conversation->workspace_id;

        /*
        | The teacher's side — membership AND `chat.reply`, both.
        |
        | ⚠️ MEMBERSHIP ALONE IS NOT THE TEACHER'S SIDE, and reading it that way
        | opens every room to every student. A student is a member of no workspace
        | in production — which is exactly what makes the mistake invisible there
        | and visible in a fixture, where `addWorkspaceMember()` attaches one. The
        | permission is what actually separates the two sides, here as in the
        | private branch below.
        */
        if ($user->workspaces()->withoutGlobalScopes()->whereKey($workspaceId)->exists()
            && $user->hasPermissionTo(Permissions::CHAT_REPLY)
        ) {
            return Response::allow();
        }

        if ($conversation->class_session_id !== null) {
            return $this->seats->hasSeatInSession($user, (int) $conversation->class_session_id)
                ? Response::allow()
                : Response::deny('هذه الغرفة لمن حجز مقعداً في الحصّة.');
        }

        if ($conversation->lesson_id !== null) {
            $courseId = Lesson::query()
                ->withoutWorkspaceScope()
                ->whereKey($conversation->lesson_id)
                ->value('course_id');

            return $courseId !== null && $this->enrollments->hasActiveEnrollment($user, (int) $courseId)
                ? Response::allow()
                : Response::deny('هذه الغرفة لطلاب هذا الكورس.');
        }

        // A public conversation attached to nothing has no entitlement to check,
        // which is a row that should not exist — refused rather than opened.
        return Response::deny('لست طرفاً في هذه المحادثة.');
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
