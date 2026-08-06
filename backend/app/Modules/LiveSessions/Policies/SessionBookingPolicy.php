<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Policies;

use App\Models\User;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

class SessionBookingPolicy extends BasePolicy
{
    public function view(User $user, SessionBooking $booking): Response
    {
        if ((int) $booking->student_user_id === (int) $user->getKey()) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($booking))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::SESSIONS_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Cancelling a seat.
     *
     * The student's own, or the teacher's on their behalf. Whether the deadline
     * has passed is not decided here — CancelBooking decides that, because a
     * late cancellation is allowed, it is simply still charged (FR-010).
     */
    public function delete(User $user, SessionBooking $booking): Response
    {
        if ((int) $booking->student_user_id === (int) $user->getKey()) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($booking))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::SESSIONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }
}
