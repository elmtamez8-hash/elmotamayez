<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Listeners;

use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\Settlement\Actions\AccrueTeachingUnits;
use App\Modules\Settlement\Events\TeachingUnitAccrued;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;

/**
 * The bridge, and the only one.
 *
 * `SessionDelivered` rather than `AttendanceConfirmed`, for two reasons that are
 * facts about the 005 code rather than preferences: the attendance event fires
 * unconditionally — including for a session the teacher never taught, which
 * FR-007ج forbids earning on — and it carries no seat count, so listening to it
 * would force this module to derive the number from live bookings, which
 * FR-007أ forbids outright.
 *
 * Nothing here reaches into LiveSessions beyond the event's payload, and nothing
 * in either direction touches the student billing context.
 *
 * ⚠️ QUEUED, AND IT WAS A PLAIN CLASS — WHICH MADE IT A SINGLE POINT OF FAILURE
 * FOR THE WHOLE END OF A LESSON.
 *
 * `SessionDelivered` fires exactly ONCE in a session's life: `CloseClassSession`
 * returns early on a terminal status, and by the time the event is dispatched the
 * transaction that wrote `status = completed` has already committed. Run
 * synchronously, a throw in here — a deadlock, a transient failure, the
 * unguarded `create()` in `compensateEmptySession()` — propagated straight back
 * into `CloseClassSession::handle()` and killed the three lines below it:
 * `SessionCompleted` (so the teacher's counters never moved AND the recording was
 * never ingested), `AttendanceConfirmed`, and `SendSessionReportsJob` (so no
 * guardian was ever told about that lesson). All three lost permanently, with
 * nothing that re-fires them and no sweep that notices.
 *
 * Queued, the failure lands in `failed_jobs` where it belongs and the rest of the
 * close runs. `ShouldQueueAfterCommit` for the same reason its Payments
 * sibling carries it — and the two are now the same shape, which is the point:
 * one event with one synchronous listener among queued ones is a hazard that is
 * invisible until the day that listener throws.
 */
class AccrueUnitsOnDelivery implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly AccrueTeachingUnits $action,
    ) {}

    public function handle(SessionDelivered $event): void
    {
        /*
        | ⚠️ ONE TRANSACTION AROUND THE UNITS *AND* THEIR LEDGER LINES.
        |
        | `RecordUnitInLedger` is synchronous, so each dispatch below writes the
        | entry that makes a unit money. Unwrapped, a throw half-way — a ledger
        | write, or the third `create()` inside the Action — left some units on
        | disk with no entry behind them, and the retry could not repair it: the
        | unique index answers «already accrued» for every unit that exists, the
        | Action returns them as `null`, nothing is dispatched for them, and the
        | teacher is short that lesson's pay for ever with the unit row insisting
        | it was counted. Rolled back together, the retry starts from nothing and
        | writes every unit and every entry.
        |
        | The duplicate-key catch inside the Action is safe here: a unique
        | violation does not poison the transaction on MySQL or SQLite.
        */
        DB::transaction(function () use ($event): void {
            $units = $this->action->handle(
                $event->session,
                $event->billableSeats,
                $event->subscriptionSeats,
                // ٠٣٥ · FR-014 — the teacher is paid on the CHARGED seats. Null is
                // «not judged», which the Action reads as the pre-035 rule.
                $event->chargedSeats,
            );

            foreach ($units as $unit) {
                // Announced only once it is actually an earning. A unit still waiting
                // for its recording is not money yet, and a listener told otherwise
                // would put a figure in front of the teacher that can still go away.
                if ($unit->status->countsTowardsTotal()) {
                    TeachingUnitAccrued::dispatch($unit);
                }
            }
        });
    }
}
