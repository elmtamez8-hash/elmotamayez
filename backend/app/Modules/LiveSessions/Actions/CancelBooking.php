<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Gives a seat up.
 *
 * A late cancellation is allowed and still charged (FR-010) — the deadline is
 * not a lock on the button, it is what the seat costs after it passes. Refusing
 * the cancellation instead would leave the student marked absent from a session
 * they told us they could not attend, which is a worse record of the same fact.
 *
 * The seat only returns to the pool when it was given up in time: a late
 * cancellation keeps `seats_taken` where it is, so the count frozen at the
 * deadline stays true (FR-060).
 */
class CancelBooking extends Action
{
    public function handle(SessionBooking $booking, ?string $reason = null): SessionBooking
    {
        if ($booking->status !== BookingStatus::Booked) {
            throw new DomainException('هذا الحجز ملغى بالفعل.');
        }

        $session = $booking->classSession;

        if ($session === null) {
            // A booking whose session vanished is corrupt data, not a state to
            // limp along in: silently treating it as cancellable would decrement
            // a seat count on a row nobody can name.
            throw new DomainException('الحصة المرتبطة بهذا الحجز غير موجودة.');
        }

        $inWindow = now()->lessThan($session->cancellationDeadline());

        DB::transaction(function () use ($booking, $session, $reason, $inWindow): void {
            $booking->forceFill([
                'status' => $inWindow ? BookingStatus::CancelledInWindow : BookingStatus::CancelledLate,
                'is_billable' => ! $inWindow,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            if ($inWindow) {
                ClassSession::query()
                    ->whereKey($session->getKey())
                    ->where('seats_taken', '>', 0)
                    ->decrement('seats_taken');
            }
        });

        return $booking->refresh();
    }

    /**
     * The system takes a seat back — the student did nothing (027 · FR-045).
     *
     * ⚠️ A NAMED SECOND ENTRY, NOT A FLAG ON `handle()`. `handle($booking, $reason, true)`
     * says nothing at its call site and is read backwards by the first caller
     * written after it; this repository's `BookSeat` wrote that rule down for the
     * same shape.
     *
     * ⚠️ AND IT WRITES `Released`, WHICH IS THE WHOLE POINT. The enum already
     * carries the distinction and says why: «the student did nothing — their
     * eligibility lapsed and the system took the seat back». Filing a system
     * release under a cancellation puts a mark against somebody who cancelled
     * nothing — and worse, it makes the seat unrecoverable: the auto-booker skips
     * a cancelled row on purpose (FR-044), so a student who lapses, is released,
     * then renews would never be booked into those sessions again. Paid, unbooked,
     * silent.
     *
     * ⚠️ AND THE DEADLINE IS NOT ASKED. `handle()` bills a late cancellation
     * because the student chose the moment; nobody chose this one. Taking the
     * seat away AND charging for it is the worst of both, and `isBillable()`
     * already answers false for `Released` — this keeps the column agreeing with
     * the enum.
     */
    public function release(SessionBooking $booking, string $reason): SessionBooking
    {
        if ($booking->status !== BookingStatus::Booked) {
            // Not an error: two sweeps can reach the same seat, and a release
            // that has already happened is the outcome asked for.
            return $booking;
        }

        DB::transaction(function () use ($booking, $reason): void {
            $booking->forceFill([
                'status' => BookingStatus::Released,
                'is_billable' => false,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            ClassSession::query()
                ->whereKey($booking->class_session_id)
                ->where('seats_taken', '>', 0)
                ->decrement('seats_taken');
        });

        return $booking->refresh();
    }
}
