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
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Support\WorkspaceContext;
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
    public function __construct(
        private readonly EnrollmentDirectory $enrollments,
        private readonly WorkspaceContext $context,
    ) {}

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

        // NFR-001أ — a teacher may not act on, or learn anything about, someone
        // with no active enrolment in their own workspace. Without this the uuid
        // is an identity probe: pass any user's and the response comes back
        // carrying their name.
        //
        // In the Action rather than the FormRequest, because the panel and the
        // seeders come through this door too (Constitution II) — and because
        // `exists:users,uuid` answers a different question entirely.
        if ($student !== null && ! $this->enrollments->hasActiveEnrollmentInWorkspace(
            $student,
            (int) $this->context->id(),
        )) {
            throw new DomainException('هذا الطالب ليس من طلابك.');
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
            // Range comparison, not whereDate(): a function on the column costs
            // the `(workspace_id, status, starts_at)` index. The end bound is the
            // START of the next day, which is what "the whole of ends_on" means
            // for a timestamp column — `<= ends_on` would silently drop every
            // session on the freeze's last day after midnight.
            ->where('starts_at', '>=', $period->starts_on->copy()->startOfDay())
            ->where('starts_at', '<', $period->ends_on->copy()->addDay()->startOfDay())
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
        // Read before the release, for the same reason CancelClassSession does:
        // a listener running afterwards cannot tell a seat taken away from a
        // seat given back weeks ago.
        $seatHolderIds = array_values($session->bookings()
            ->where('status', BookingStatus::Booked)
            ->pluck('student_user_id')
            ->map(fn ($id): int => (int) $id)
            ->all());

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
            $seatHolderIds,
        );

        return count($seatHolderIds);
    }
}
