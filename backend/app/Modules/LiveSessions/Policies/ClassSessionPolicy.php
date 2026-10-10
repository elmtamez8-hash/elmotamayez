<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Policies;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Support\WorkspacePermission;
use Illuminate\Auth\Access\Response;

/**
 * ⛔ EVERY STAFF DOOR ON A SESSION ALSO ASKS THE ASSISTANT SCOPE (spec 010 ·
 * FR-005). Until 2026-09-29 `view`, `update`/`cancel`, `host` and `create` asked
 * the permission and the workspace alone, so an assistant confined to one course
 * who held `sessions.host` / `sessions.manage` opened, moved, cancelled, hosted
 * and scheduled the sessions of every course in the workspace — while
 * `CoursePolicy` refused them those courses. The scope is asked AFTER the
 * permission and after every student branch: a student's seat, enrolment and
 * heartbeat never reach it, and a teacher, an owner or an unconfined assistant
 * pass it as a no-op.
 *
 * A session with NO course is outside every confinement — the answer
 * `AttendancePolicy::override()` and `MediaAssetPolicy` already give, decided
 * once in `AssistantScopeDirectory::mayActOnCourse()`. Only historic rows carry
 * one: `ScheduleClassSession::requireCourse()` refuses a course-less session at
 * the door every scheduler (the form, availability generation, an accepted
 * private request) comes through.
 */
class ClassSessionPolicy extends BasePolicy
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    /**
     * The single session's DETAIL (`GET /class-sessions/{uuid}`), on top of `view`.
     *
     * ⛔ A STUDENT READS THE SESSIONS OF THEIR OWN COURSE AND GROUP (security scan
     * 2026-10-10, F17). `view` admits any active enrolment in the workspace —
     * the booking door relies on that to answer its own, more precise refusals
     * — so the detail let a student of a free course read any session by uuid,
     * a classmate's booked one-to-one hour among them, whose group name carries
     * that classmate's full name. A seat holder and the workspace's staff read
     * it as before; a course-less session keeps the workspace-wide reading.
     */
    public function readDetails(User $user, ClassSession $session): Response
    {
        if ($session->holdsSeat($user)
            || WorkspacePermission::holds($user, (int) $session->workspace_id, Permissions::SESSIONS_VIEW)) {
            return Response::allow();
        }

        if ($session->course_id === null) {
            return Response::allow();
        }

        $mayRead = $this->enrollments->hasActiveEnrollment($user, (int) $session->course_id)
            && ($session->cohort_id === null
                || app(CohortDirectory::class)->wasEverMember($user, (int) $session->cohort_id));

        return $mayRead ? Response::allow() : Response::deny();
    }

    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::SESSIONS_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * ⚠️ THE OWNERSHIP BRANCH IS NOT A CONVENIENCE — WITHOUT IT NO REAL STUDENT
     * COULD BOOK A SESSION, OPEN ONE, OR BE TOLD WHY THEY COULD NOT.
     *
     * `belongsToCurrentWorkspace()` compares against `WorkspaceContext::id()`,
     * which falls back to `users.last_workspace_id` — and NOTHING on a student's
     * path ever writes that column. The only writers in the tree are
     * `CreateWorkspace` (the owner), `WorkspaceContext::set()` — reached solely
     * from `AcceptInvitation` and `SwitchWorkspace`, both of which are about
     * MEMBERS — and the two seeders. Enrolment writes nothing. So for every
     * student who signed up and bought a course the column is null, the context
     * is null, and this check denied. Its sibling denied too, from the other
     * side: with no team id spatie hands out no roles at all, so
     * `can(SESSIONS_VIEW)` is false for the same person for the same reason.
     *
     * Four endpoints authorise on this one ability, and all four were dead:
     * `POST /class-sessions/{uuid}/book` (‏the booking itself),
     * `GET /class-sessions/{uuid}`, `GET …/attendance` (‏the student's own row)
     * and `GET …/eligibility` — the last of which is `FR-038`'s whole answer,
     * fetched by `UnlockNotice` exactly when a join has just been refused, and
     * swallowed there by design. So the student read «تعذّر الدخول» and NOTHING
     * ELSE, for ever, which is the outage that requirement exists to prevent.
     *
     * ⚠️ AND EVERY TEST OF IT PASSED. `Sanctum::actingAs()` + `setCurrentWorkspace()`
     * gives the test student the context production never gives them, and the two
     * seeders stamp `last_workspace_id` on their demo students — so the manual
     * walk could not see it either until the fixture was built without a seeder.
     * Found on 2026-08-26 by nulling that one column on a working student and
     * watching four endpoints turn 403 (spec 017 · `T051`).
     *
     * The branch is ownership rather than permission — the same distinction
     * `AttendanceController` already draws for a reader without `ATTENDANCE_VIEW`
     * — and it asks the two questions in their existing spellings: the seat
     * through `ClassSession::holdsSeat()`, and the enrolment through the same
     * `EnrollmentDirectory` method `BookingEligibility::refusalReason()` asks. A
     * third spelling is how one answer reaches the screen and another the door.
     *
     * ⚠️ SO `enrollments` IS READ TWICE ON EVERY BOOKING REQUEST — here at the
     * door, and again inside `BookingEligibility` behind it — AND IT STAYS THAT
     * WAY ON PURPOSE. A `scoped()` memo on `EloquentEnrollmentDirectory` closed
     * it (measured 2026-09-16: 2 ⇒ 1 on `/eligibility` and on `…/book`) and was
     * REVERTED the same day: its invalidation hung on model events, a bulk
     * `update()` fires none, and `ArchiveAfterEnrollmentEndsTest` caught a
     * student whose enrolment had ENDED still posting into the chat — 201 where
     * 403 was demanded. A remembered entitlement fails OPEN, silently, on an
     * authorization read; the whole saving was one query.
     *
     * ⚠️ AND `holdsSeat()` IS ASKED FIRST, which is why the duplicate is not
     * universal: a student who already holds a seat never reaches the enrolment
     * line at all. Anyone measuring this must build a student WITHOUT a seat, or
     * they are measuring a branch that does not run.
     */
    public function view(User $user, ClassSession $session): Response
    {
        if ($session->holdsSeat($user)
            || $this->enrollments->hasActiveEnrollmentInWorkspace($user, (int) $session->workspace_id)) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($session))->denied()) {
            return $workspaceCheck;
        }

        if (! $user->can(Permissions::SESSIONS_VIEW)) {
            return Response::deny();
        }

        /*
        | ⚠️ A READ, SCOPED ON PURPOSE: the staff list (`ClassSessionController::
        | index`) names a confined assistant's own courses only, and a list that
        | hides a row while its uuid still opens is the draft-course hole #281
        | closed. This also takes the far session's register (`GET …/attendance`
        | authorises on this ability) — `ATTENDANCE_VIEW` stays workspace-wide
        | for a session the assistant may open.
        */
        return $this->withinAssistantScope($user, $session);
    }

    /**
     * Scheduling. `$course` is the course the new session(s) will belong to,
     * and every caller passes it (`store`, `generate`, and the two
     * group-assignment doors). Without one a confined assistant is refused — a
     * session is never scheduled outside a course.
     */
    public function create(User $user, ?Course $course = null): Response
    {
        if (! $user->can(Permissions::SESSIONS_MANAGE)) {
            return Response::deny();
        }

        $workspaceId = $course === null
            ? app(WorkspaceContext::class)->id()
            : (int) $course->workspace_id;

        // No course and no context: a super admin operating globally, whom no
        // assignment confines.
        if ($workspaceId === null) {
            return Response::allow();
        }

        return app(AssistantScopeDirectory::class)->mayActOnCourse(
            $user,
            $workspaceId,
            $course === null ? null : (int) $course->getKey(),
        )
            ? Response::allow()
            : Response::deny('هذا الكورس خارج نطاق عملك.');
    }

    public function update(User $user, ClassSession $session): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($session))->denied()) {
            return $workspaceCheck;
        }

        if (! $user->can(Permissions::SESSIONS_MANAGE)) {
            return Response::deny();
        }

        return $this->withinAssistantScope($user, $session);
    }

    public function cancel(User $user, ClassSession $session): Response
    {
        return $this->update($user, $session);
    }

    /**
     * Mute, remove, end.
     *
     * A separate ability from update: managing the calendar and controlling a
     * live room are different powers, and spec 010 will hand one to assistants
     * without the other.
     */
    public function host(User $user, ClassSession $session): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($session))->denied()) {
            return $workspaceCheck;
        }

        /*
        | ⚠️ THE PERMISSION FIRST, THE SCOPE SECOND — AND THE ORDER IS A COST.
        | `RoomRevocation::isHost()` asks this on every heartbeat of every
        | participant; a student stops at the permission before the scope
        | directory is consulted at all.
        */
        if (! $user->can(Permissions::SESSIONS_HOST)) {
            return Response::deny();
        }

        return $this->withinAssistantScope($user, $session);
    }

    /**
     * Spec 010 · FR-005 — asked BESIDE the permission, never instead of it. A
     * `null` course stays `null`, which the directory refuses for a confined
     * assistant and passes for everybody else.
     */
    private function withinAssistantScope(User $user, ClassSession $session): Response
    {
        $courseId = $session->course_id;

        return app(AssistantScopeDirectory::class)->mayActOnCourse(
            $user,
            (int) $session->workspace_id,
            $courseId === null ? null : (int) $courseId,
        )
            ? Response::allow()
            : Response::deny('هذه الحصّة خارج نطاق عملك.');
    }
}
