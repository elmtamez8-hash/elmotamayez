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
}
