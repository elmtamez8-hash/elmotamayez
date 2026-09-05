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
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\AttendanceLadder;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SubscriptionDirectory;
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
 *  3. AttendanceConfirmed is announced — a moment with a timestamp rather than
 *     an implication, so the register becoming final is something a consumer can
 *     be told about instead of inferring.
 *
 *     ⚠️ It is NO LONGER the gate on money. FR-051 once forbade consumption
 *     before it; 006's Q-6 retired that, because consumption is per frozen SEAT
 *     and never reads an attendance status, so there is nothing for it to wait
 *     on. SessionDelivered is dispatched FIRST for the reason written at that
 *     line: it fires exactly once in a session's life.
 */
class CloseClassSession extends Action
{
    public function __construct(
        private readonly CloseBroadcastRoom $closeRoom,
        private readonly AttendanceLadder $ladder,
        private readonly SessionSettings $settings,
        private readonly SubscriptionDirectory $subscriptions,
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

        if ($session->delivered_at !== null) {
            // FIRST of the four, and the order is load-bearing. This Action
            // returns early on a terminal status, so SessionDelivered fires
            // exactly once in a session's life: a throw in any listener
            // dispatched ahead of it swallows the event permanently, and nothing
            // ever fires it again. The teacher then goes unpaid and the seat
            // uncharged — and because withholding is DERIVED from the balance,
            // the student's record stays clean and they carry on booking and
            // opening assets (research › R17).
            //
            // It used to sit last, below the guardian report. That ordering also
            // read as "money follows AttendanceConfirmed" (the old FR-051), which
            // Q-6 retired: consumption is per frozen seat and never touches an
            // attendance status, so there is nothing left for it to wait on.
            //
            // The frozen seat count travels with it because it is a fact about a
            // past moment, and a consumer must never recompute it from live
            // bookings (FR-060).
            SessionDelivered::dispatch(
                $session,
                $session->billable_seats ?? 0,
                $this->subscriptionSeatsOf($session),
            );
        }

        SessionCompleted::dispatch($session);
        AttendanceConfirmed::dispatch($session);

        // The guardian's report, after the announced delay. Dispatched here
        // rather than from a listener on SessionCompleted because it belongs to
        // the register this Action just closed — the delay is measured from the
        // moment the register became final, which is this line.
        SendSessionReportsJob::dispatch((int) $session->getKey())
            ->delay(now()->addMinutes($this->settings->reportDelayMinutes()));

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

    /**
     * Which of this session's seat holders were paid for by a subscription
     * (027 · FR-048).
     *
     * ⚠️ ASKED HERE, NOT IN SETTLEMENT. `ContextIsolationTest` fails the build on
     * the first `use App\Modules\Payments` under `Modules/Settlement/`, and the
     * delivery event is the one sanctioned bridge between the two contexts. What
     * crosses it is a list of student ids — a fact, not a lookup.
     *
     * The seat set matches the one settlement prices: `Booked` and
     * `CancelledLate`, because a late cancellation is billed. And the moment is
     * the session's own start, never `now()`: a subscription that ended between
     * the lesson and its closing still paid for that lesson.
     *
     * @return list<int>
     */
    private function subscriptionSeatsOf(ClassSession $session): array
    {
        if ($session->course_id === null) {
            return [];
        }

        $seatHolderIds = SessionBooking::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $session->getKey())
            ->whereIn('status', [BookingStatus::Booked->value, BookingStatus::CancelledLate->value])
            ->pluck('student_user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $seatHolderIds = array_values($seatHolderIds);

        if ($seatHolderIds === []) {
            return [];
        }

        return $this->subscriptions->subscriberIdsAmong(
            $seatHolderIds,
            (int) $session->course_id,
            $session->type->value,
            $session->starts_at,
        );
    }
}
