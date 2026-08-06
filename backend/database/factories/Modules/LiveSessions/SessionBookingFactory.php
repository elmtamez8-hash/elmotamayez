<?php

declare(strict_types=1);

namespace Database\Factories\Modules\LiveSessions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SessionBooking> */
class SessionBookingFactory extends Factory
{
    protected $model = SessionBooking::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'class_session_id' => ClassSession::factory(),
            'student_user_id' => User::factory(),
            'status' => BookingStatus::Booked,
            'is_billable' => true,
            'booked_at' => now(),
        ];
    }

    public function cancelledLate(): static
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::CancelledLate,
            // Still charged — that is what the deadline is for (FR-010).
            'is_billable' => true,
            'cancelled_at' => now(),
        ]);
    }

    public function cancelledInWindow(): static
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::CancelledInWindow,
            'is_billable' => false,
            'cancelled_at' => now(),
        ]);
    }
}
