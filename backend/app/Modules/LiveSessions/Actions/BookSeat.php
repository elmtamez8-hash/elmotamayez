<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Takes a seat, or refuses.
 *
 * The guard against overbooking is one conditional UPDATE:
 *
 *     UPDATE class_sessions SET seats_taken = seats_taken + 1
 *     WHERE id = ? AND seats_taken < seats_total
 *
 * If it affected a row, the seat is ours. If it affected none, the session was
 * full at the instant we asked. There is no window between the check and the
 * write because there is no separate check — which is the whole difference
 * between this and `if (count < seats) insert`, the textbook race.
 *
 * `lockForUpdate()` would also be correct on MySQL, and was rejected for a
 * specific reason: it is a no-op on SQLite, so the test proving SC-001 would
 * pass locally while proving nothing about production. A guard we cannot test is
 * worse than one we can, even when it is theoretically stronger.
 */
class BookSeat extends Action
{
    public function __construct(
        private readonly BookingEligibility $eligibility,
    ) {}

    public function handle(ClassSession $session, User $student): SessionBooking
    {
        if (! $session->status->acceptsBookings()) {
            throw new DomainException('هذه الحصة لم تعد متاحة للحجز.');
        }

        if ($session->starts_at->isPast()) {
            throw new DomainException('لا يمكن حجز حصة بدأت أو انتهت.');
        }

        $refusal = $this->eligibility->refusalReason($session, $student);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        return DB::transaction(function () use ($session, $student): SessionBooking {
            $claimed = ClassSession::query()
                ->whereKey($session->getKey())
                ->whereColumn('seats_taken', '<', 'seats_total')
                ->increment('seats_taken');

            if ($claimed === 0) {
                throw new DomainException('اكتملت مقاعد هذه الحصة.');
            }

            try {
                $booking = SessionBooking::query()->create([
                    'workspace_id' => $session->workspace_id,
                    'class_session_id' => $session->getKey(),
                    'student_user_id' => $student->getKey(),
                    'status' => BookingStatus::Booked,
                    'is_billable' => true,
                    'booked_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // The student already holds a seat. Give the one we just claimed
                // back, or a double-tap on the button would eat a seat nobody
                // occupies.
                ClassSession::query()->whereKey($session->getKey())->decrement('seats_taken');

                throw new DomainException('لديك مقعد محجوز في هذه الحصة بالفعل.');
            }

            $session->refresh();

            return $booking;
        });
    }
}
