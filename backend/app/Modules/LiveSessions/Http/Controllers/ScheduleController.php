<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Actions\GetStudentSchedule;
use App\Modules\LiveSessions\Http\Resources\SessionBookingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's own timetable, across every teacher.
 *
 * No policy call: there is no resource to authorise against. The authorisation
 * IS the identity — the action only ever reads rows belonging to the
 * authenticated user, and there is no parameter by which to ask for anyone
 * else's.
 */
class ScheduleController extends Controller
{
    public function index(Request $request, GetStudentSchedule $action): JsonResponse
    {
        $bookings = $action->handle($this->currentUser($request));

        return response()->json(['data' => SessionBookingResource::collection($bookings)]);
    }

    public function next(Request $request, GetStudentSchedule $action): JsonResponse
    {
        $booking = $action->next($this->currentUser($request));

        $session = $booking?->classSession;

        if ($booking === null || $session === null) {
            // Not a 404. "You have no upcoming sessions" is an answer, and the
            // screen shows an empty state for it rather than an error (FR-055).
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => SessionBookingResource::make($booking),
            // Counted on the server. A countdown built from the browser's clock
            // shows a time that does not exist on a machine whose clock is off
            // (SC-016).
            'seconds_until_start' => max(0, (int) now()->diffInSeconds($session->starts_at, false)),
        ]);
    }
}
