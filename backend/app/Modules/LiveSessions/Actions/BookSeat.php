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
        $this->assertBookable($session);

        // ⚠️ `openingRefusal`, not `refusalReason`: booking is one of the two
        // doors FR-041 names, so 008's unlock condition is asked here too.
        return $this->claim($session, $student, $this->eligibility->openingRefusal($session, $student));
    }

    /**
     * The seat behind a private-session request the teacher has just granted
     * (023 · FR-019 · FR-020).
     *
     * ⚠️ A NAMED SECOND ENTRY POINT, NOT A BOOLEAN PARAMETER AND NOT A SECOND
     * COPY OF THE CLAIM. A flag is read backwards by the first caller written
     * after it — `handle($session, $student, true)` says nothing at its call
     * site — and a second atomic UPDATE somewhere else is the two-spellings
     * defect this repository has paid for six times. The claim below is the one
     * that runs for both doors.
     *
     * ⚠️ AND IT ASKS `refusalReason()`, NOT `openingRefusal()`. The unlock gate
     * (008 · FR-036) governs the next lesson on a course PATH; a private hour is
     * what a student asks for precisely because they are behind on it, so asking
     * it here would refuse exactly the student the feature exists for — and
     * would refuse them at the teacher's press, after the teacher already
     * decided. The money and enrolment conditions are asked, because those the
     * teacher cannot waive.
     */
    public function claimGrantedSeat(ClassSession $session, User $student): SessionBooking
    {
        $this->assertBookable($session);

        return $this->claim($session, $student, $this->eligibility->refusalReason($session, $student));
    }

    private function assertBookable(ClassSession $session): void
    {
        if (! $session->status->acceptsBookings()) {
            throw new DomainException('هذه الحصة لم تعد متاحة للحجز.');
        }

        if ($session->starts_at->isPast()) {
            throw new DomainException('لا يمكن حجز حصة بدأت أو انتهت.');
        }
    }

    private function claim(ClassSession $session, User $student, ?string $refusal): SessionBooking
    {
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
