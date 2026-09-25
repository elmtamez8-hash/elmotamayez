<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\FreezeBillableSeatsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Support\SessionClash;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Lifts a freeze — and hands back the future sessions it suspended.
 *
 * ⛔ `Suspended` HAD NO WAY OUT. Deleting the period used to remove the row and
 * nothing else, so every session it had suspended stayed suspended for ever: no
 * booking can reach it (`acceptsBookings()` is `Scheduled` alone), no Action
 * moves it back, and `SessionClash` ignores it — so the teacher's calendar showed
 * an hour as free that was still holding a dead session. A freeze lifted a day
 * after it was declared cost every lesson inside it, permanently.
 *
 * What comes back HERE is the SESSION, never its seats. Those were released and
 * every holder was told «لن تُعقد»; this Action does not put them back. A revived
 * session is bookable again, and a credit-paying student who still wants the hour
 * books it. ⚠️ A GROUP SUBSCRIBER IS THE EXCEPTION, and it is not decided here:
 * the delete moves their subscription's end, and `EffectiveSubscriptionEnd`
 * re-claims their seats up to the new end and releases the ones past it (owner
 * decision 2026-09-25, option 3) — their month paid for those hours, and FR-039
 * takes a subscriber's seats «without the student pressing anything».
 *
 * Three things keep a session suspended, and each is reported rather than
 * swallowed:
 *
 * - it has already started — lifting a freeze does not reopen yesterday;
 * - another freeze still covers it — lifting one of two overlapping periods must
 *   not undo the other;
 * - the teacher has put something else in that hour since — the slot looked free
 *   while the session was suspended, and reviving it would double-book them.
 */
class DeleteFreezePeriod extends Action
{
    /**
     * @return array{restored: list<ClassSession>, kept: list<ClassSession>}
     */
    public function handle(FreezePeriod $period): array
    {
        // Selected BEFORE the delete, while the period can still describe which
        // sessions were its own.
        $candidates = $this->suspendedBy($period);

        /*
        | ⚠️ ONE TRANSACTION AROUND THE DELETE AND EVERY REVIVAL. The delete
        | announces `FreezePeriodChanged`, and its listener
        | (`RecomputeSubscriptionEnds`, `ShouldQueueAfterCommit`) re-claims each
        | subscriber's group seats up to the new end (owner decision 2026-09-25,
        | option 3b). With no transaction open that job is pushed the instant the
        | row goes — while every session this freeze suspended is still
        | `Suspended`, which the claim skips — so a workspace freeze lifted gave
        | nobody their seats back. Inside it, the job waits until the sessions it
        | is meant to fill are bookable again. Each revival keeps its own inner
        | transaction (a savepoint here), so one refused revival still leaves the
        | others standing.
        */
        return DB::transaction(function () use ($period, $candidates): array {
            // Through the model, never a query: `booted()` announces the delete,
            // and spec 011 takes back the subscription extension this period
            // granted.
            $period->delete();

            $restored = [];
            $kept = [];

            foreach ($candidates as $session) {
                if ($this->restore($session)) {
                    $restored[] = $session;
                } else {
                    $kept[] = $session;
                }
            }

            return ['restored' => $restored, 'kept' => $kept];
        });
    }

    /**
     * The sessions this period suspended that have not happened yet.
     *
     * The same range `CreateFreezePeriod` suspended through (`startingInside`),
     * narrowed the same way it was: a workspace-wide period suspended every
     * session in it, a period on one student suspended only that student's
     * INDIVIDUAL sessions — a group one never stops for one student — and those
     * are recognised by the seat the freeze released.
     *
     * @return list<ClassSession>
     */
    private function suspendedBy(FreezePeriod $period): array
    {
        return array_values(ClassSession::query()
            ->where('status', ClassSessionStatus::Suspended)
            ->startingInside($period)
            ->where('starts_at', '>', now())
            ->when(
                $period->student_user_id !== null,
                fn ($query) => $query
                    ->where('type', ClassSessionType::Individual)
                    ->whereHas(
                        'bookings',
                        fn ($booking) => $booking
                            ->where('student_user_id', $period->student_user_id)
                            ->where('status', BookingStatus::Released),
                    ),
            )
            ->orderBy('starts_at')
            ->get()
            ->all());
    }

    private function restore(ClassSession $session): bool
    {
        $startsAt = CarbonImmutable::instance($session->starts_at);

        if ($this->stillFrozen($session, $startsAt)) {
            return false;
        }

        try {
            DB::transaction(function () use ($session, $startsAt): void {
                // The hour may have been given to something else while this
                // session sat suspended. Asked inside the transaction because the
                // clash check CLAIMS the teacher's calendar (see SessionClash).
                SessionClash::assertFree(
                    (int) $session->teacher_profile_id,
                    $startsAt,
                    CarbonImmutable::instance($session->ends_at),
                    (int) $session->getKey(),
                );

                // The claim: only a session still suspended and still in the
                // future moves. A second lift, or a cancellation in between,
                // leaves this one alone.
                $claimed = ClassSession::query()->withoutWorkspaceScope()
                    ->whereKey($session->getKey())
                    ->where('status', ClassSessionStatus::Suspended->value)
                    ->where('starts_at', '>', now())
                    ->update([
                        'status' => ClassSessionStatus::Scheduled->value,
                        // ⛔ A REVIVED SESSION IS OWED A NEW SEAT COUNT —
                        // `UpdateClassSession::rearmSeatFreeze()`'s rule, reached
                        // from a second door. `FreezeBillableSeatsJob` does not
                        // skip a suspended session, so one whose cancellation
                        // deadline passed during the freeze already carries
                        // `billable_seats = 0`, frozen for ever: everyone who
                        // books the revived hour would be taught unbilled, and
                        // the teacher paid for an empty room.
                        'billable_seats' => null,
                        'seats_frozen_at' => null,
                    ]);

                if ($claimed !== 1) {
                    throw new DomainException('تغيّرت حالة الحصة.');
                }
            });
        } catch (DomainException) {
            return false;
        }

        $session->refresh();

        if ($session->interruption_note === 'zero_attendance') {
            // Written by the seat-count job that ran while the session was
            // suspended; it described an emptiness the freeze caused.
            $session->forceFill(['interruption_note' => null])->save();
        }

        // Armed for the session's own start, like every other arming: a revival
        // is a second birth, so a session revived inside its own cancellation
        // window settles its count when it starts.
        // `afterCommit`: this now runs inside `handle()`'s transaction, and a
        // worker reading the session before it commits would find it still
        // `Suspended`.
        FreezeBillableSeatsJob::dispatch((int) $session->getKey(), $session->starts_at->getTimestamp())
            ->delay($session->billableSeatsFreezeAt(now()))
            ->afterCommit();

        return true;
    }

    /**
     * Whether a period OTHER than the one being lifted still covers this hour.
     *
     * A workspace-wide period covers every session; a period on one student
     * covers the individual session whose seat that student held.
     */
    private function stillFrozen(ClassSession $session, CarbonImmutable $startsAt): bool
    {
        $studentUserId = null;

        if ($session->type === ClassSessionType::Individual) {
            $studentUserId = $session->bookings()
                ->where('status', BookingStatus::Released)
                ->latest('id')
                ->value('student_user_id');
        }

        return FreezePeriod::query()
            ->covering($startsAt, $studentUserId === null ? null : (int) $studentUserId)
            ->exists();
    }
}
