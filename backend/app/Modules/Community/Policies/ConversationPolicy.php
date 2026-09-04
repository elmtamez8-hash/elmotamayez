<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Community\Support\BanReader;
use App\Modules\Community\Support\WriteBanReader;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionAttendanceDirectory;
use App\Shared\Contracts\TeacherOffboardingDirectory;
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
        private readonly TeacherOffboardingDirectory $departures,
        private readonly CohortDirectory $cohorts,
        private readonly WriteBanReader $writeBans,
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
            return Response::deny('تم إيقاف الكتابة عن حسابك عند هذا المدرّس. يمكنك القراءة.');
        }

        /*
        | ⚠️ ASKED ON EVERY KIND, AND ASKED HERE RATHER THAN ON THE ROW. When the
        | teacher's exit completes (013 · FR-037) every non-student membership in
        | this workspace is gone — so nobody is left holding `chat.reply` and every
        | thread in it, private or room, is a place a student can still write into
        | and never be answered. Their enrolment cannot express it: FR-035 keeps
        | the course they paid for until their term ends, which is exactly the
        | condition the private branch below reads.
        |
        | ⚠️ AND `StartConversation` AUTHORISES AN UNSAVED `Conversation` AGAINST
        | THIS SAME ABILITY, which is why the answer is not a `closed_at` column: a
        | row that does not exist yet carries no stamp, and a second guard written
        | beside the other door is the two-spellings defect `BookingEligibility`
        | already paid for. One question, one place.
        */
        if ($this->departures->hasDeparted((int) $conversation->workspace_id)) {
            return Response::deny('أنهى هذا المدرّس عمله على المنصّة، والمحادثة صارت للقراءة فقط.');
        }

        if ($conversation->kind->isPublic()) {
            /*
            | ⚠️ THE LOCK IS READ FOR THE ROOM AND FOR NOBODY ELSE, and the person
            | who set it is exempt. A teacher closes the discussion during an
            | explanation and reopens it for questions; locking themselves out of
            | it means they cannot answer the last question left on screen, and
            | cannot say why they closed it. `chat.moderate` is the exemption
            | rather than «the host», because an assistant with moderation is
            | exactly who is watching the room while the teacher talks.
            |
            | It is a COLUMN here and not a derived condition, unlike the teacher's
            | departure above: a lock is a decision somebody made about one room at
            | one moment, and there is nothing else to derive it from.
            */
            if ($conversation->locked_at !== null
                && ! $user->hasPermissionTo(Permissions::CHAT_MODERATE)) {
                return Response::deny('أغلق المدرّس النقاش مؤقّتاً. يمكنك القراءة.');
            }

            /*
            | ⚠️ AND THE PERSON THE HOST PUT OUT OF THE LESSON, who could carry on
            | typing here after being removed from the video.
            |
            | The seat is deliberately untouched by a removal — cancelling it
            | would repossess a session the student PAID for over a moment's
            | behaviour — so the seat check above cannot see this, and neither can
            | the lock, which silences the whole class to reach one person. The
            | only instrument left was a workspace-wide ban: recorded, appealable,
            | and covering every thread with that teacher for ever.
            |
            | Read for the room it happened in and no other, and the moderator is
            | exempt for exactly the reason the lock exempts them.
            */
            if ($conversation->class_session_id !== null
                && ! $user->hasPermissionTo(Permissions::CHAT_MODERATE)
                && $this->seats->wasRemovedFromSession($user, (int) $conversation->class_session_id)) {
                return Response::deny('أخرجك المدرّس من هذه الحصة، فلا يمكنك الكتابة في نقاشها.');
            }

            /*
            | ⚠️ AND THE COHORT ROOM'S SECOND DOOR. `view()` admitted whoever was
            | EVER a member; writing needs a membership that is open now
            | (FR-046). Without this the student who left the group carries on
            | posting into it for ever — reading their old answers is the point,
            | and answering back in a group they are no longer in is not.
            |
            | `chat.moderate` is exempt for the reason the lock exempts it: the
            | teacher holds no membership in their own cohort and would otherwise
            | be locked out of every group thread they run.
            */
            if ($conversation->cohort_id !== null
                && ! $user->hasPermissionTo(Permissions::CHAT_MODERATE)
                && ! $this->cohorts->isCurrentMember($user, (int) $conversation->cohort_id)) {
                return Response::deny('انتقلت إلى مجموعة أخرى، وهذا النقاش صار للقراءة فقط.');
            }

            /*
            | ⚠️ THE PER-THREAD BAN, AND IT IS THE LAST OF THE FOUR REFUSALS ON
            | PURPOSE (FR-047). Above it stand the workspace-wide ban and the
            | teacher's departure, both asked on every kind — so a person banned
            | from the workspace, or writing to a teacher who has left, is
            | refused for the wider reason and told the wider truth. Reaching this
            | line means the only thing wrong is this thread and this hour.
            |
            | The sentence carries the reason AND the time, because a refusal with
            | neither is read as a fault and retried until the ban lapses.
            */
            if (! $user->hasPermissionTo(Permissions::CHAT_MODERATE)) {
                $ban = $this->writeBans->activeBan((int) $user->getKey(), (int) $conversation->getKey());

                if ($ban !== null) {
                    return Response::deny($ban->expires_at === null
                        ? 'أوقف المدرّس كتابتك في هذا النقاش. السبب: '.$ban->reason
                        : 'أوقف المدرّس كتابتك في هذا النقاش حتى '.$ban->expires_at->format('H:i').'. السبب: '.$ban->reason);
                }
            }

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
     * Closing a room's discussion and opening it again (`FR-018`).
     *
     * ⚠️ MEMBERSHIP AS WELL AS THE PERMISSION, the `markHelpful` pair. A student
     * is a member of no workspace in production, so `hasPermissionTo` alone looks
     * like enough and is not — in a fixture the permission can be granted to
     * anybody, and the pair is what actually separates the two sides of a room.
     *
     * `chat.moderate` and not `chat.reply`: a lock is an act on what the room may
     * say, ticked separately from being able to answer in it. Handing it to
     * everyone who can reply gives every assistant who answers a question the
     * power to stop the rest of the class asking one.
     */
    public function moderate(User $user, Conversation $conversation): Response
    {
        $isMember = $user->workspaces()
            ->withoutGlobalScopes()
            ->whereKey($conversation->workspace_id)
            ->exists();

        if (! $isMember || ! $user->hasPermissionTo(Permissions::CHAT_MODERATE)) {
            return Response::deny('إدارة النقاش من صلاحيّة المدرّس ومن فوّضه.');
        }

        return Response::allow();
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

        /*
        | ⚠️ THE COHORT ROOM READS ON «WAS EVER A MEMBER», AND WRITES ON «IS ONE
        | NOW» — the only kind in this product whose two doors ask different
        | questions (FR-046). A student who moved to another group keeps the
        | answers they were given in the old one: the thread is where their
        | teacher explained something, and taking the archive away on the day
        | they change their Saturday is the FR-014 defect in a second shape.
        |
        | The write side is refused in `post()` and NOT here, because a denial
        | here is a denial of reading — `post()` calls `view()` first.
        */
        if ($conversation->cohort_id !== null) {
            return $this->cohorts->wasEverMember($user, (int) $conversation->cohort_id)
                ? Response::allow()
                : Response::deny('هذا النقاش لأعضاء هذه المجموعة.');
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
