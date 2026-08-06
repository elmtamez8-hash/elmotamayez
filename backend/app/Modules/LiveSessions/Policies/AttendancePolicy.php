<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Policies;

use App\Models\User;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may read and change a mark.
 *
 * Row ownership comes first and needs no permission: a student reads their own
 * attendance because it is theirs. A teacher needs both the permission and the
 * workspace boundary — the permission answers "may this role ever look?", the
 * boundary answers "at this row?" (Constitution I, guardian rule).
 */
class AttendancePolicy extends BasePolicy
{
    public function view(User $user, Attendance $attendance): Response
    {
        if ((int) $attendance->student_user_id === (int) $user->getKey()) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($attendance))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::ATTENDANCE_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Marking someone present by hand.
     *
     * The edit window is NOT checked here. It is enforced in OverrideAttendance,
     * because it is a business rule about time rather than about identity, and
     * Constitution II puts those in the Action where the panel and the seeders
     * meet them too.
     */
    public function override(User $user, Attendance $attendance): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($attendance))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::ATTENDANCE_OVERRIDE)
            ? Response::allow()
            : Response::deny();
    }
}
