<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Policies;

use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Auth\Access\Response;

class ClassSessionPolicy extends BasePolicy
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

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

        return $user->can(Permissions::SESSIONS_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::SESSIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, ClassSession $session): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($session))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::SESSIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
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

        return $user->can(Permissions::SESSIONS_HOST)
            ? Response::allow()
            : Response::deny();
    }
}
