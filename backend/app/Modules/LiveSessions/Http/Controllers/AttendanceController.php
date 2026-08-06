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
    /**
     * The register: every billable seat, present or not (FR-023أ) — for whoever
     * is entitled to read a register.
     *
     * SESSIONS_VIEW and ATTENDANCE_VIEW are two different questions. The first
     * says "you may see the sessions you can book"; every student holds it. The
     * second says "you may read who attended one", and answering the second with
     * the first hands each student the class roll: names, stay durations, and
     * the reason a teacher changed someone's mark.
     *
     * So a reader without ATTENDANCE_VIEW gets their own row and nothing else.
     * That is ownership rather than permission, which is why it is not a 403:
     * their attendance is theirs, and the register they are not entitled to is
     * simply not there.
     */
    public function index(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $viewer = $this->currentUser($request);

        $attendances = $session->attendances()
            ->with('student')
            ->unless(
                $viewer->can(Permissions::ATTENDANCE_VIEW),
                fn ($query) => $query->where('student_user_id', $viewer->getKey()),
            )
            ->get();

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
