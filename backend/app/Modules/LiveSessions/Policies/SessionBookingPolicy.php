<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Policies;

use App\Models\User;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Policies\Concerns\AsksAssistantScope;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

class SessionBookingPolicy extends BasePolicy
{
    use AsksAssistantScope;

    public function view(User $user, SessionBooking $booking): Response
    {
        if ((int) $booking->student_user_id === (int) $user->getKey()) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($booking))->denied()) {
            return $workspaceCheck;
        }

        if (! $user->can(Permissions::SESSIONS_VIEW)) {
            return Response::deny();
        }

        return $this->withinAssistantScope(
            $user,
            (int) $booking->workspace_id,
            $this->courseOfSession((int) $booking->class_session_id),
        );
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

        if (! $user->can(Permissions::SESSIONS_MANAGE)) {
            return Response::deny();
        }

        // ⛔ Spec 010 · FR-005: a confined assistant releases the seats of their
        // own courses' sessions only.
        return $this->withinAssistantScope(
            $user,
            (int) $booking->workspace_id,
            $this->courseOfSession((int) $booking->class_session_id),
        );
    }
}
