<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Actions\OverrideAttendance;
use App\Modules\LiveSessions\Actions\SubmitSessionFeedback;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Http\Requests\SubmitFeedbackRequest;
use App\Modules\LiveSessions\Http\Resources\AttendanceResource;
use App\Modules\LiveSessions\Http\Resources\ClassSessionFeedbackResource;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Tenancy\Support\Permissions;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    /** The full register: every billable seat, present or not (FR-023أ). */
    public function index(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $attendances = $session->attendances()->with('student')->get();

        return response()->json(['data' => AttendanceResource::collection($attendances)]);
    }

    /**
     * The teacher's remarks on this session's students (FR-036).
     *
     * Guarded by `update` rather than `view`: writing on a student's record is
     * the same power as managing the session, and a reader must not gain it by
     * being able to read the register.
     */
    public function feedback(
        SubmitFeedbackRequest $request,
        ClassSession $session,
        SubmitSessionFeedback $action,
    ): JsonResponse {
        $this->authorize('update', $session);

        /** @var array<int, array{student_uuid: string, rating?: int|null, note?: string|null}> $entries */
        $entries = $request->validated('entries');

        try {
            $saved = $action->handle($session, $this->currentUser($request), $entries);
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'student_has_no_seat',
            ], 422);
        }

        return response()->json(['data' => ClassSessionFeedbackResource::collection($saved)]);
    }

    public function override(Request $request, Attendance $attendance, OverrideAttendance $action): JsonResponse
    {
        $this->authorize('override', $attendance);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(AttendanceStatus::class)],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $attendance = $action->handle(
                $attendance,
                AttendanceStatus::from((string) $validated['status']),
                $this->currentUser($request),
                (string) $validated['reason'],
                // Past the window the teacher's own permission is not enough;
                // it takes someone who can change platform settings.
                $this->currentUser($request)->can(Permissions::SETTINGS_UPDATE),
            );
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'attendance_window_closed',
            ], 403);
        }

        return response()->json(AttendanceResource::make($attendance));
    }
}
