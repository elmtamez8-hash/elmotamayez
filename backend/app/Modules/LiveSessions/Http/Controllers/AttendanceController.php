<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LiveSessions\Actions\OverrideAttendance;
use App\Modules\LiveSessions\Actions\SubmitSessionFeedback;
use App\Modules\LiveSessions\Actions\SummariseChildAttendance;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Http\Requests\SubmitFeedbackRequest;
use App\Modules\LiveSessions\Http\Resources\AttendanceResource;
use App\Modules\LiveSessions\Http\Resources\ChildAttendanceSummaryResource;
use App\Modules\LiveSessions\Http\Resources\ClassSessionFeedbackResource;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\GuardianChild;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
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
            // The teacher has a row — it is what proves the session was
            // delivered — but it is not a line in their own class roll.
            ->excludingHost($session)
            ->with('student')
            ->unless(
                $viewer->can(Permissions::ATTENDANCE_VIEW),
                fn ($query) => $query->where('student_user_id', $viewer->getKey()),
            )
            ->get();

        return response()->json(['data' => AttendanceResource::collection($attendances)]);
    }

    /**
     * How a child is attending, for the guardian who is entitled to ask (029 ·
     * US3 · FR-020).
     *
     * ⚠️ A SUMMARY IS NOT THE REGISTER, AND THAT IS THE WHOLE REASON IT IS A
     * SEPARATE ROUTE. {@see self::index()} answers per session and per person —
     * names, stay durations, and the reason a teacher changed a mark — and it is
     * gated on `ATTENDANCE_VIEW`, which no guardian holds. What a parent asked
     * for is four numbers about their own child, and the guard for that is the
     * relation plus the `attendance` permission, never a workspace permission
     * borrowed because it was the nearest one that existed.
     *
     * `days` is optional and bounded: an unbounded window is one request that
     * walks a whole school life, and the Action clamps rather than refusing
     * because a client sending 5000 wants «all of it», not an error.
     */
    public function childSummary(
        Request $request,
        SummariseChildAttendance $action,
        GuardianDirectory $guardians,
    ): JsonResponse {
        $child = GuardianChild::named(
            $request,
            $this->currentUser($request),
            $guardians,
            GuardianPermission::Attendance,
        );

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:'.SummariseChildAttendance::MAX_DAYS],
        ]);

        return response()->json([
            'data' => ChildAttendanceSummaryResource::make(
                $action->handle($child, (int) ($validated['days'] ?? 30)),
            ),
        ]);
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
