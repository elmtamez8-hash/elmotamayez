<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Events\SessionCancelled;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A stretch of days where nothing counts (FR-039).
 *
 * The period itself writes nothing to attendance rows, counters or streaks — it
 * is *read* by scheduling, booking and the counting jobs (research §R11). That
 * is the whole reason resuming afterwards cannot fail: FR-043 asks that counters
 * come back with their previous values, and the only way to guarantee that is
 * never to have moved them. There is no resumption code in this module because
 * there is nothing to undo.
 *
 * What it does write is the sessions already on the calendar inside it. Those
 * are suspended, not deleted (FR-040): a deleted session takes its register, its
 * bookings and any question about them with it, and a family asking "what
 * happened to Tuesday" deserves an answer that still exists.
 *
 * The return value names what was suspended and how many people were told. A
 * freeze that quietly takes away twelve booked hours is the failure mode the
 * last edge case in the spec is about — the teacher has to see the cost of the
 * button they just pressed.
 */
class CreateFreezePeriod extends Action
{
    /**
     * @return array{period: FreezePeriod, suspended: list<ClassSession>, notified: int}
     */
    public function handle(
        User $actor,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
        ?User $student = null,
        ?string $reason = null,
    ): array {
        if ($endsOn->lessThan($startsOn)) {
            throw new DomainException('تاريخ نهاية التجميد قبل بدايته.');
        }

        $period = FreezePeriod::query()->create([
            'student_user_id' => $student?->getKey(),
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'reason' => $reason,
            'created_by' => $actor->getKey(),
        ]);

        [$suspended, $notified] = $this->suspendSessionsIn($period);

        return ['period' => $period, 'suspended' => $suspended, 'notified' => $notified];
    }

    /**
     * @return array{0: list<ClassSession>, 1: int}
     */
    private function suspendSessionsIn(FreezePeriod $period): array
    {
        $sessions = ClassSession::query()
            ->where('status', ClassSessionStatus::Scheduled)
            ->whereDate('starts_at', '>=', $period->starts_on)
            ->whereDate('starts_at', '<=', $period->ends_on)
            // A freeze on one student suspends only the sessions that student
            // holds a seat in — the teacher's other classes carry on (FR-039).
            ->when(
                $period->student_user_id !== null,
                fn ($query) => $query->whereHas(
                    'bookings',
                    fn ($booking) => $booking
                        ->where('student_user_id', $period->student_user_id)
                        ->where('status', BookingStatus::Booked),
                ),
            )
            ->get();

        $notified = 0;
        $suspended = [];

        foreach ($sessions as $session) {
            $notified += $this->suspend($session, $period);
            $suspended[] = $session;
        }

        return [$suspended, $notified];
    }

    private function suspend(ClassSession $session, FreezePeriod $period): int
    {
        $seats = (int) $session->bookings()->where('status', BookingStatus::Booked)->count();

        DB::transaction(function () use ($session): void {
            // Released, not cancelled: the students did nothing, and filing it
            // against them would put a mark on the wrong person.
            $session->bookings()
                ->where('status', BookingStatus::Booked)
                ->update([
                    'status' => BookingStatus::Released,
                    'is_billable' => false,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'فترة تجميد',
                ]);

            $session->forceFill([
                'status' => ClassSessionStatus::Suspended,
                'seats_taken' => 0,
            ])->save();
        });

        // Same event as an outright cancellation, because from a seat's point of
        // view it is the same news. Dispatched after the transaction so a message
        // never describes a rollback.
        SessionCancelled::dispatch(
            $session,
            $period->reason === null ? 'فترة تجميد' : 'فترة تجميد: '.$period->reason,
        );

        return $seats;
    }
}
