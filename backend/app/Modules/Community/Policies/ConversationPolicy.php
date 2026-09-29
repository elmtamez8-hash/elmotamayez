<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\ConversationParticipant;
use App\Modules\Community\Support\BanReader;
use App\Modules\Community\Support\ConversationSides;
use App\Modules\Community\Support\TeacherInboxSettings;
use App\Modules\Community\Support\TeacherStanding;
use App\Modules\Community\Support\WriteBanReader;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionAttendanceDirectory;
use App\Shared\Contracts\SessionContentAccess;
use App\Shared\Contracts\TeacherOffboardingDirectory;
use App\Shared\Support\UserClock;
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
    /** Shown on the button (`ReadContactOptions`) and at the door alike. */
    public const TEACHING_ACCOUNT = 'حسابك حساب تدريس، والمراسلة من هنا للطلاب وأولياء الأمور.';

    public function __construct(
        private readonly AssistantScopeDirectory $assistants,
        private readonly EnrollmentDirectory $enrollments,
        private readonly SessionAttendanceDirectory $seats,
        private readonly BanReader $bans,
        private readonly TeacherOffboardingDirectory $departures,
        private readonly CohortDirectory $cohorts,
        private readonly WriteBanReader $writeBans,
        private readonly SessionContentAccess $sessionContent,
        private readonly ConversationSides $sides,
        private readonly TeacherInboxSettings $inbox,
        private readonly TeacherStanding $standing,
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

        $staff = $this->teacherSide($user, $conversation);

        if ($staff->allowed()) {
            return $staff;
        }

        /*
        | ⚠️ A GUARDIAN READS THEIR CHILD'S THREAD, AND ONLY WITH `messages`
        | (owner decision 2026-09-28). Asked after the teacher's side because that
        | is the common reader and its answer costs nothing more; asked at all
        | because the guardian writes AS the child, and writing into a thread you
        | cannot read is typing into the dark. Revoke the relation or untick the
        | permission and both doors close on the next request.
        */
        if ($this->sides->isAuthorisedGuardian($user, $conversation)) {
            return Response::allow();
        }

        return $staff;
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
            | ⛔ EVERY MODERATOR EXEMPTION BELOW ASKS THIS, NEVER THE PERMISSION
            | ALONE (spec 010 · FR-005). A confined assistant may read a room
            | outside their courses AS A STUDENT — `publicRoom()` falls through to
            | the seat when the staff branch refuses on scope — and there they are
            | a student at every door below: stopped by the lock, by the ejection
            | and by the per-thread ban, bound by the group membership. The
            | permission is asked first so a student never pays for the scope read.
            */
            $moderatesHere = $user->hasPermissionTo(Permissions::CHAT_MODERATE)
                && $this->withinStaffScope($user, $conversation)->allowed();

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
            if ($conversation->locked_at !== null && ! $moderatesHere) {
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
                && ! $moderatesHere
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
                && ! $moderatesHere
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
            if (! $moderatesHere) {
                $ban = $this->writeBans->activeBan((int) $user->getKey(), (int) $conversation->getKey());

                if ($ban !== null) {
                    return Response::deny($ban->expires_at === null
                        ? 'أوقف المدرّس كتابتك في هذا النقاش. السبب: '.$ban->reason
                        : 'أوقف المدرّس كتابتك في هذا النقاش حتى '.UserClock::format($user, $ban->expires_at).'. السبب: '.$ban->reason);
                }
            }

            /*
            | ⛔ ٠٣٥ · R10 — AN EXPLICIT REFUSAL, NOT A COMMENT SAYING WHY THERE IS
            | NONE. `post()` reaches here by calling `view()`, and `view()`'s room
            | branch now admits whoever OPENED the hour as well as whoever held a
            | seat. Without this line, widening the read silently widened the
            | WRITE: a student who never sat in the lesson pays one credit and is
            | typing into a thread every seat holder reads.
            |
            | `chat.moderate` is exempt for the reason the lock and the ejection
            | both exempt it — a teacher or an assistant holds no seat of their
            | own in the room they run.
            */
            if ($conversation->class_session_id !== null
                && ! $this->runsTheRoom($user, $conversation)
                && ! $this->seats->hasSeatInSession($user, (int) $conversation->class_session_id)) {
                return Response::deny('فتحتَ محتوى هذه الحصّة للقراءة، والكتابة في نقاشها لأصحاب المقاعد.');
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
        | ⛔ THE STUDENT OF A PRIVATE THREAD IS A LEARNER (security review of #276).
        | Without these two refusals a TEACHER account — `teachesOnPlatform()` is
        | true the moment public signup creates their workspace, before approval —
        | could omit `student` and write to any other teacher as a «prospect», and
        | a GUARDIAN could open a thread as themselves instead of as a child. The
        | same sentence `ReadContactOptions` shows, so the button and the door say
        | one thing.
        */
        if ($student->teachesOnPlatform()) {
            return Response::deny(self::TEACHING_ACCOUNT);
        }

        if ($student->platform_role === PlatformRole::Parent) {
            return Response::deny('وليّ الأمر يراسل المدرّس باسم ابنه، فاختر الابن أولاً.');
        }

        $speaksForStudent = $this->sides->speaksForStudent($user, $conversation);

        /*
        | ⛔ A GUARDIAN CARRIES THE CHILD'S BAN (security review of #276). The ban
        | above is asked of the SENDER; a guardian writes as the child, into the
        | child's thread, so a banned student's parent was the way around it.
        */
        if ($speaksForStudent
            && (int) $user->getKey() !== $studentId
            && $this->bans->isBanned($studentId, (int) $conversation->workspace_id)) {
            return Response::deny('تم إيقاف الكتابة عن حساب ابنك عند هذا المدرّس. يمكنك القراءة.');
        }

        /*
        | A SUBSCRIBER WRITES WITHOUT LIMIT, and «subscriber» is this one
        | predicate — an active or completed enrolment in the workspace. A live
        | subscription and a cohort seat both imply one (see
        | `ProspectAllowance`), so there is nothing to add beside it.
        */
        if ($this->enrollments->hasActiveEnrollmentInWorkspace($student, (int) $conversation->workspace_id)) {
            return Response::allow();
        }

        /*
        | ⛔ A PROSPECT (owner decisions 2026-09-28). Until that day this line
        | refused every non-subscriber on both sides — so nobody could ask a
        | teacher a question before paying. A former student is a prospect again,
        | with the cap counted from the day their access ended (`ProspectAllowance`).
        */
        if ($speaksForStudent) {
            // The teacher's switch, «استقبال رسائل من غير المشتركين».
            if (! $this->inbox->acceptsProspectsIn((int) $conversation->workspace_id)) {
                return Response::deny('لا يستقبل هذا المدرّس رسائل جديدة من غير طلابه حالياً.');
            }

            // A suspended teacher, or an applicant not yet approved, is not
            // somebody the platform lets strangers court.
            if (! $this->standing->reachableByProspects((int) $conversation->workspace_id)) {
                return Response::deny('لا يستقبل هذا المدرّس رسائل من غير طلابه الآن.');
            }

            // HOW MUCH is `PostMessage`'s question — it needs a row lock.
            return Response::allow();
        }

        /*
        | ⚠️ THE TEACHER'S SIDE ANSWERS A PROSPECT AND NEVER OPENS ONE. Without this
        | «راسِل» would take any account's uuid on the platform and start a thread
        | with it — a cold-message channel to every student who ever signed up.
        | A thread exists only once somebody has written in it, so «it exists and
        | has a message» is «somebody on the student's side wrote».
        |
        | ⛔ AND THE SWITCH DOES NOT BIND THIS SIDE (owner decision 2026-09-28).
        | Turning prospects off stops strangers writing; it does not silence the
        | teacher in threads that already exist — including a former student's.
        */
        return $conversation->exists && $conversation->last_message_id !== null
            ? Response::allow()
            : Response::deny('هذا الطالب لا يدرس عندكم، فلا تُبدأ المحادثة معه. يمكنك الردّ إن راسلك أولاً.');
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

        // The lock and both write-ban doors, and the moderator's hide of one
        // message (`ModerateMessage`) — a confined assistant moderates the
        // rooms of their own courses only.
        return $this->withinStaffScope($user, $conversation);
    }

    /**
     * Spec 010 · FR-005 for the teaching side of a thread: asked BESIDE the
     * permission, never instead of it, and never on a student's branch.
     *
     * A room is asked about the course it hangs off — the session's, the
     * lesson's or the group's. ⚠️ A ROOM WITH NO COURSE (a course-less session)
     * IS REFUSED TO A CONFINED ASSISTANT, the answer `ClassSessionPolicy` gives
     * the session itself. A private thread names no course, so it is asked about
     * its student — `teacherSide()`'s question.
     *
     * A teacher, an owner and an unconfined assistant pass it as a no-op.
     */
    private function withinStaffScope(User $user, Conversation $conversation): Response
    {
        $workspaceId = (int) $conversation->workspace_id;

        if (! $conversation->kind->isPublic()) {
            return $this->assistants->mayActOnStudent($user, $workspaceId, (int) $conversation->student_user_id)
                ? Response::allow()
                : Response::deny('هذا الطالب خارج نطاق عملك.');
        }

        return $this->assistants->mayActOnCourse($user, $workspaceId, $this->courseOfRoom($conversation))
            ? Response::allow()
            : Response::deny('هذا النقاش خارج نطاق عملك.');
    }

    /**
     * The course a room hangs off, read with the workspace scope bypassed — a
     * scoped read that came back empty would be a `null` course and refuse a
     * confined assistant their OWN course's room.
     */
    private function courseOfRoom(Conversation $conversation): ?int
    {
        $courseId = match (true) {
            $conversation->class_session_id !== null => ClassSession::query()
                ->withoutWorkspaceScope()
                ->whereKey($conversation->class_session_id)
                ->value('course_id'),
            $conversation->lesson_id !== null => Lesson::query()
                ->withoutWorkspaceScope()
                ->whereKey($conversation->lesson_id)
                ->value('course_id'),
            $conversation->cohort_id !== null => Cohort::query()
                ->withoutWorkspaceScope()
                ->whereKey($conversation->cohort_id)
                ->value('course_id'),
            default => null,
        };

        return $courseId === null ? null : (int) $courseId;
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
    /**
     * The teaching side of a room: whoever may answer in it, or moderate it.
     *
     * ⛔ IT IS THE SAME PREDICATE `publicRoom()` LETS IN AT THE TOP, and it is a
     * method rather than a repeated condition for the reason this file already
     * pays twice over: two spellings of one question put one answer on the screen
     * and another at the door. Written as `chat.moderate` alone, ٠٣٥'s write
     * refusal silenced the ASSISTANT — who holds `chat.reply`, holds no seat of
     * their own, and is exactly the person watching the room while the teacher
     * talks.
     *
     * ⛔ AND THE ASSISTANT SCOPE (spec 010 · FR-005): a confined assistant who
     * reads a far room through a seat of their own is a student there, and a
     * student writes into a session room only from a seat.
     */
    private function runsTheRoom(User $user, Conversation $conversation): bool
    {
        $staff = $user->hasPermissionTo(Permissions::CHAT_MODERATE)
            || ($user->workspaces()->withoutGlobalScopes()
                ->whereKey((int) $conversation->workspace_id)->exists()
                && $user->hasPermissionTo(Permissions::CHAT_REPLY));

        return $staff && $this->withinStaffScope($user, $conversation)->allowed();
    }

    /**
     * Is this person on the TEACHING side of this thread — not merely a reader?
     *
     * ⚠️ NOT `view()`. Since a confined assistant may read a room outside their
     * courses through a student entitlement of their own (a seat, an enrolment,
     * a group), «may read» no longer implies «reads as staff». A staff power —
     * `MessagePolicy::markHelpful`, which pays a student points — asks THIS.
     *
     * A room: membership, `chat.reply` and the scope. A private thread:
     * `teacherSide()`, the same three.
     */
    public function staffSide(User $user, Conversation $conversation): Response
    {
        if (! $conversation->kind->isPublic()) {
            return $this->teacherSide($user, $conversation);
        }

        return $this->roomStaffSide($user, $conversation)
            ?? Response::deny('لا تملك صلاحيّة الردّ في هذا النقاش.');
    }

    /**
     * The room's teaching side: `null` when the person is not staff of this
     * workspace at all, otherwise the scope's verdict.
     */
    private function roomStaffSide(User $user, Conversation $conversation): ?Response
    {
        /*
        | Membership AND `chat.reply`, both.
        |
        | ⚠️ MEMBERSHIP ALONE IS NOT THE TEACHER'S SIDE, and reading it that way
        | opens every room to every student. A student is a member of no workspace
        | in production — which is exactly what makes the mistake invisible there
        | and visible in a fixture, where `addWorkspaceMember()` attaches one. The
        | permission is what actually separates the two sides, here as in the
        | private branch.
        */
        if (! $user->workspaces()->withoutGlobalScopes()->whereKey((int) $conversation->workspace_id)->exists()
            || ! $user->hasPermissionTo(Permissions::CHAT_REPLY)
        ) {
            return null;
        }

        return $this->withinStaffScope($user, $conversation);
    }

    private function publicRoom(User $user, Conversation $conversation): Response
    {
        $staff = $this->roomStaffSide($user, $conversation);

        if ($staff?->allowed()) {
            return $staff;
        }

        /*
        | ⛔ THE ASSISTANT SCOPE (spec 010 · FR-005), AND A SCOPE REFUSAL FALLS
        | THROUGH TO THE STUDENT'S DOORS (owner decision 2026-09-29). Until that
        | day a confined assistant holding `chat.reply` read, and wrote into, the
        | room of every session, lesson and group in the workspace — while
        | `ClassSessionPolicy` refused them the far session itself. The first fix
        | RETURNED the refusal, which also took away a room they had a STUDENT'S
        | right to: an assistant who booked a seat in another teacher's-course
        | session, or is enrolled in that course, or sits in that group.
        |
        | Falling through is safe because the branches below grant a STUDENT'S
        | read and nothing more. Every staff power asks the scope on its own and
        | never asks `view()`: `moderate()` (lock · write-bans · the moderator's
        | hide) ends in `withinStaffScope()`, «مفيدة» asks `staffSide()`, and
        | `post()`'s moderator exemptions and seat rule ask `$moderatesHere` /
        | `runsTheRoom()`, both scoped. Somebody with no student entitlement is
        | still refused — told the staff reason, which is the true one.
        */
        $student = $this->studentRoom($user, $conversation);

        return $student->denied() && $staff !== null ? $staff : $student;
    }

    /** A student's entitlement to read a room: the seat, the enrolment, the group. */
    private function studentRoom(User $user, Conversation $conversation): Response
    {
        if ($conversation->class_session_id !== null) {
            /*
            | ٠٣٥ · FR-008 · R10 — THE SEAT **OR** THE OPENED HOUR, AND READING
            | ONLY.
            |
            | The seat stays first because it is the ordinary case and costs one
            | cheap query; the contract is the second door, for whoever gave
            | notice, kept their credit, and later spent one on purpose. Both are
            | READS: `post()` carries its own explicit refusal, because this
            | method is reached through `view()` and a denial here would take the
            | archive away rather than the pen.
            */
            return $this->seats->hasSeatInSession($user, (int) $conversation->class_session_id)
                || $this->sessionContent->mayOpenSessionContent($user, (int) $conversation->class_session_id)
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
