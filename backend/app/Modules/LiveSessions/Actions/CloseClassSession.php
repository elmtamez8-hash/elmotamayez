<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Events\AttendanceConfirmed;
use App\Modules\LiveSessions\Events\SessionCompleted;
use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\AttendanceLadder;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Ends a session and settles what happened in it.
 *
 * Three things happen here and nowhere else:
 *
 *  1. The register is completed. Every frozen seat gets a row, present or not
 *     (FR-023أ) — a register that lists only the people who showed up is a list
 *     of attendees, not a register, and SC-022 counts it against the frozen
 *     seats to prove nothing is missing.
 *  2. Delivery is decided. SessionDelivered fires only when the teacher joined,
 *     stayed long enough, and the session ended normally (FR-056). It is the
 *     single place that judgement is made, so the rule is read in one file
 *     rather than chased through controllers.
 *  3. AttendanceConfirmed is announced. FR-051 forbids any financial
 *     consumption before it, so it is a moment with a timestamp rather than an
 *     implication — SC-015 asserts the ordering, and you cannot assert an
 *     implication.
 */
class CloseClassSession extends Action
{
    public function __construct(
        private readonly CloseBroadcastRoom $closeRoom,
        private readonly AttendanceLadder $ladder,
    ) {}

    public function handle(ClassSession $session): ClassSession
    {
        if ($session->status->isTerminal()) {
            return $session;
        }

        $this->closeRoom->handle($session);

        DB::transaction(function () use ($session): void {
            $this->completeRegister($session);

            $session->forceFill([
                'status' => ClassSessionStatus::Completed,
                'delivered_at' => $this->wasDelivered($session) ? now() : null,
            ])->save();
        });

        SessionCompleted::dispatch($session);
        AttendanceConfirmed::dispatch($session);

        if ($session->delivered_at !== null) {
            // The event billing (006) and payout (014) hang off. The frozen seat
            // count travels with it because it is a fact about a past moment, and
            // a consumer must never recompute it from live bookings (FR-060).
            SessionDelivered::dispatch($session, $session->billable_seats ?? 0);
        }

        return $session;
    }

    /** A row for every billable seat, whether its owner appeared or not. */
    private function completeRegister(ClassSession $session): void
    {
        $seats = $session->bookings()
            ->whereIn('status', [BookingStatus::Booked, BookingStatus::CancelledLate])
            ->get();

        foreach ($seats as $seat) {
            $attendance = Attendance::query()->firstOrCreate(
                [
                    'class_session_id' => $session->getKey(),
                    'student_user_id' => $seat->student_user_id,
                ],
                [
                    'workspace_id' => $session->workspace_id,
                    'status' => AttendanceStatus::Absent,
                    'auto_status' => AttendanceStatus::Absent,
                    'source' => AttendanceSource::Automatic,
                    'stay_seconds' => 0,
                ],
            );

            $attendance->forceFill(['confirmed_at' => now()])->save();
        }
    }

    /**
     * FR-056 — all three, or it did not happen.
     *
     * The teacher's own attendance row is the evidence: they are a participant
     * in the register like anyone else, so "did the teacher show up" needs no
     * second mechanism.
     */
    private function wasDelivered(ClassSession $session): bool
    {
        if ($session->status === ClassSessionStatus::Cancelled) {
            return false;
        }

        $teacherUserId = $session->teacherProfile?->user_id;

        if ($teacherUserId === null) {
            return false;
        }

        $teacherAttendance = $session->attendances()
            ->where('student_user_id', $teacherUserId)
            ->first();

        if ($teacherAttendance === null || $teacherAttendance->first_joined_at === null) {
            return false;
        }

        return $this->ladder->teacherStayed($session, $teacherAttendance->stay_seconds);
    }
}
