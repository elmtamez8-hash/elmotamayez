<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\BookingEligibility;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Takes back seats held by students who are no longer entitled to them (FR-012).
 *
 * `Released`, not cancelled: the student did nothing. Their enrolment lapsed, or
 * a freeze now covers them, and the seat should go to somebody who can use it.
 * Recording that as a cancellation would count against a person who never
 * cancelled anything.
 *
 * @return int how many seats were freed
 */
class ReleaseIneligibleBookings extends Action
{
    public function __construct(
        private readonly BookingEligibility $eligibility,
    ) {}

    public function handle(ClassSession $session): int
    {
        $bookings = $session->bookings()
            ->where('status', BookingStatus::Booked)
            ->with('student')
            ->get();

        $released = 0;

        foreach ($bookings as $booking) {
            $student = $booking->student;

            if ($student === null || $this->eligibility->allows($session, $student)) {
                continue;
            }

            DB::transaction(function () use ($booking, $session): void {
                $booking->forceFill([
                    'status' => BookingStatus::Released,
                    'is_billable' => false,
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'انتهت أهلية الطالب قبل الموعد',
                ])->save();

                ClassSession::query()
                    ->whereKey($session->getKey())
                    ->where('seats_taken', '>', 0)
                    ->decrement('seats_taken');
            });

            $released++;
        }

        return $released;
    }
}
