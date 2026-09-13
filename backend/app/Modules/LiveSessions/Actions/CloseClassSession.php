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
use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
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

        /*
        | ٠٣٥ · T027 — THE STATE TRANSITION IS A CONDITIONAL UPDATE, and only the
        | winner reaches the dispatch block below.
        |
        | ⚠️ THE READ AT THE TOP OF THIS METHOD AND THE WRITE HERE USED TO BE TWO
        | STATEMENTS, and this Action has TWO senders — `CloseClassSessionJob`
        | (delayed to the end of the join window) and `CloseStaleSessionsJob`
        | (hourly). Two workers a second apart both read a non-terminal status,
        | both close, and both dispatch: the guardian gets two reports for one
        | hour, the teacher two sets of counters, and — after ٠٣٥ — two workers
        | freeze two different verdicts over a register one of them is still
        | writing. It is the same read-then-write `OpenBroadcastRoom` paid for.
        */
        $verdict = null;

        $claimed = DB::transaction(function () use ($session, &$verdict): int {
            $verdict = $this->completeRegister($session);

            $delivered = $this->wasDelivered($session) ? now() : null;

            $claimed = DB::table('class_sessions')
                ->where('id', $session->getKey())
                ->whereNotIn('status', array_map(
                    static fn (ClassSessionStatus $status): string => $status->value,
                    array_filter(
                        ClassSessionStatus::cases(),
                        static fn (ClassSessionStatus $status): bool => $status->isTerminal(),
                    ),
                ))
                ->update([
                    'status' => ClassSessionStatus::Completed->value,
                    'delivered_at' => $delivered,
                    'updated_at' => now(),
                ]);

            if ($claimed > 0) {
                // The in-memory model does not learn about a conditional UPDATE,
                // and everything below reads it.
                $session->forceFill([
                    'status' => ClassSessionStatus::Completed,
                    'delivered_at' => $delivered,
                ])->syncChanges();
                $session->setAttribute('status', ClassSessionStatus::Completed);
                $session->setAttribute('delivered_at', $delivered);
            }

            return $claimed;
        });

        if ($claimed === 0) {
            // Somebody else closed it between this method's first line and here.
            return $session->refresh();
        }

        $chargedSeats = $this->freezeSeatVerdict($session, $verdict);

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
                // ⛔ READ BACK FROM THE ROW, never off `$session`. This event
                // carries `Dispatchable` and NOT `SerializesModels`, so a queued
                // listener unserializes the attributes as they stood at
                // dispatch — and the conditional UPDATE above did not refresh
                // the model. Taken from `$session`, every queued consumer sees
                // null for ever and falls back to the pre-035 rule on EVERY
                // session, while the sweep re-fetches and answers correctly.
                $chargedSeats,
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

    /**
     * A row for every billable seat, whether its owner appeared or not — AND the
     * ٠٣٥ verdict, frozen in the same loop.
     *
     * WARNING: IN THE EXISTING `forceFill`, NOT A SECOND UPDATE. This loop already
     * costs two or three statements per seat, and a twenty-seat room closing is
     * a hot path.
     *
     * The two counts are different questions: `attended` is who reached the stay
     * bar (for display), and `charged` is who pays (the teacher's wage base).
     * A one-to-one lesson whose student never showed gives 0 and 1.
     *
     * WARNING: AND THE VERDICT IS NEVER DERIVED FROM `status` OR `auto_status`. The
     * first is written by the teacher (FR-004), and the second is wrong in BOTH
     * directions: «late» is the mark on somebody who joined late and stayed, and
     * on somebody who joined on time and left after two minutes — and 035
     * charges the first and not the second.
     *
     * @return array{attended: int, charged: int, bar: int}
     */
    private function completeRegister(ClassSession $session): array
    {
        $seats = $session->bookings()
            ->whereIn('status', [BookingStatus::Booked, BookingStatus::CancelledLate])
            ->get();

        // Read ONCE for the session, above the loop, and stored on the row: the
        // ratio is a `platform_settings` row an operator edits, so keeping the
        // number ACTUALLY APPLIED is what stops moving the setting re-judging
        // the past (SC-012).
        $bar = $this->settings->requiredStaySeconds($session);

        $notified = $this->notifiedSeatHolderIds($session);

        $attended = 0;
        $charged = 0;

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

            $reached = $attendance->stay_seconds >= $bar;

            /*
            | 035 · T029 — the shortfall rule: whoever did not reach the bar is
            | charged UNLESS they gave notice or were excused.
            |
            | The silent no-show IS charged, and that is the most important
            | sentence in this shipment: most sessions here are one-to-one, so a
            | student who simply does not turn up costs the teacher their whole
            | hour. FR-008.
            |
            | Three exemptions, and all three were missing from the first draft
            | of this rule. (The fourth — cancelled inside the window — needs no
            | branch: that seat never enters this loop, because the seat list is
            | `Booked` and `CancelledLate` alone.)
            |
            |  · `excused_at` on the BOOKING — the teacher accepted the excuse
            |    before the room closed. NOT `attendances.status = excused`,
            |    which is the pastoral mark and exempts nothing.
            |  · `removed_at` on the ATTENDANCE — FR-036: a student the teacher
            |    ejected must not also be charged for the hour they were ejected
            |    from. This Action did not mention that column at all, so
            |    «the student is punished twice» was literal.
            |  · a reschedule request nobody answered in time, or answered after
            |    the cancellation deadline — and the session moved EARLIER onto a
            |    deadline that had already passed. Both live in
            |    `notifiedSeatHolderIds()`, with the reasoning.
            */
            $exempt = $seat->excused_at !== null
                || $attendance->removed_at !== null
                || in_array((int) $seat->student_user_id, $notified, true);

            $chargeable = $reached || ! $exempt;

            if ($reached) {
                $attended++;
            }

            if ($chargeable) {
                $charged++;
            }

            $attendance->forceFill([
                'confirmed_at' => now(),
                /*
                | STAMPED MEANS «THIS SEAT IS CHARGED», and it is the ONE
                | per-seat fact 035 writes.
                |
                | It has to be the charge and not the stay, because the charge is
                | what the billing side needs per student and `charged_seats` is
                | only a COUNT. Re-deriving the exemptions over in Payments would
                | be two spellings of one question — this repository's most
                | expensive recurring defect — and the exemptions read three
                | tables that belong to this module.
                |
                | It is also exactly what the content gate needs: the silent
                | no-show is charged AND receives the hour (FR-008ج), and the
                | excused student is charged nothing AND keeps it locked until
                | they consent. One column answers both.
                |
                | NULL is «judged, and not charged». «Not judged yet» is a
                | question about the SESSION — `attended_seats IS NULL` — and
                | never about this row, because the deploy raises the code before
                | the migration and Eloquent returns null for a column that does
                | not exist yet.
                */
                'credit_verdict_at' => $chargeable ? now() : null,
            ])->save();
        }

        return ['attended' => $attended, 'charged' => $charged, 'bar' => $bar];
    }

    /**
     * Seat holders who gave notice through the OTHER door: a reschedule request.
     *
     * WARNING: THE BOUND IS THE CANCELLATION DEADLINE, NEVER THE SESSION'S START.
     * A request refused BEFORE the deadline leaves the student time to cancel
     * normally, so they are charged like anybody else who then does not appear;
     * a request filed one minute before the lesson is not notice at all but a
     * free cancellation door that wastes the seat and pays the teacher nothing,
     * which is the whole of what FR-008 was written to prevent.
     *
     * WARNING: AND THE SECOND ARM IS THE SESSION MOVED EARLIER. The proposed time
     * is refused only when it is in the PAST, so bringing a lesson forward is
     * allowed — and the one student who asked for it is not the only seat
     * holder. Everyone else wakes up to a deadline that expired before it
     * existed, and a condition nobody can satisfy is not a condition. This
     * repository has recorded that family of defect six times.
     *
     * @return list<int>
     */
    private function notifiedSeatHolderIds(ClassSession $session): array
    {
        $deadline = $session->cancellationDeadline();

        $pending = DB::table('session_reschedule_requests')
            ->where('class_session_id', $session->getKey())
            ->where(function ($query) use ($deadline): void {
                $query->where('status', SessionRescheduleRequest::PENDING)
                    ->orWhere('decided_at', '>=', $deadline);
            })
            ->pluck('student_user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $windowMinutes = $this->settings->cancellationWindowMinutes();

        $movedOntoAPastDeadline = DB::table('session_reschedule_requests')
            ->where('class_session_id', $session->getKey())
            ->where('status', SessionRescheduleRequest::APPROVED)
            ->whereNotNull('decided_at')
            ->whereColumn('to_starts_at', '<', 'from_starts_at')
            ->get(['to_starts_at', 'decided_at'])
            ->contains(static function (object $row) use ($windowMinutes): bool {
                $newDeadline = strtotime((string) $row->to_starts_at) - ($windowMinutes * 60);

                return $newDeadline < strtotime((string) $row->decided_at);
            });

        if (! $movedOntoAPastDeadline) {
            return array_values(array_unique($pending));
        }

        return array_values(array_unique(array_merge(
            $pending,
            $session->bookings()->pluck('student_user_id')
                ->map(static fn (mixed $id): int => (int) $id)->all(),
        )));
    }

    /**
     * 035 · T028 — the three columns are frozen by ONE conditional UPDATE, then
     * read back.
     *
     * WARNING: `WHERE attended_seats IS NULL` IS BOTH THE CHECK AND THE CLAIM.
     * Two workers reading the register a second apart freeze two different
     * numbers, and one of them decides a teacher's pay.
     *
     * WARNING: AND THE COUNT COMES FROM THE LOOP THAT JUST WROTE THE ROWS, never
     * from a naive `stay_seconds >= bar` query. The HOST has an attendance row
     * ON PURPOSE — `wasDelivered()` reads it — so a query without
     * `Attendance::scopeExcludingHost()` grants the teacher an extra teaching
     * unit for attending their own lesson, in every session, for ever.
     *
     * @param  array{attended: int, charged: int, bar: int}|null  $verdict
     * @return int|null the frozen charged count, read back from the row
     */
    private function freezeSeatVerdict(ClassSession $session, ?array $verdict): ?int
    {
        if ($verdict === null) {
            return null;
        }

        DB::table('class_sessions')
            ->where('id', $session->getKey())
            ->whereNull('attended_seats')
            ->update([
                'attended_seats' => $verdict['attended'],
                'charged_seats' => $verdict['charged'],
                'verdict_stay_seconds' => $verdict['bar'],
                'updated_at' => now(),
            ]);

        $frozen = DB::table('class_sessions')
            ->where('id', $session->getKey())
            ->first(['attended_seats', 'charged_seats']);

        $session->setAttribute('attended_seats', $frozen?->attended_seats);
        $session->setAttribute('charged_seats', $frozen?->charged_seats);

        return $frozen?->charged_seats === null ? null : (int) $frozen->charged_seats;
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
