<?php

declare(strict_types=1);

namespace Database\Factories\Modules\LiveSessions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Attendance> */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'class_session_id' => ClassSession::factory(),
            'student_user_id' => User::factory(),
            'status' => AttendanceStatus::Absent,
            'source' => AttendanceSource::Automatic,
            'auto_status' => AttendanceStatus::Absent,
            'stay_seconds' => 0,
        ];
    }

    public function present(): static
    {
        return $this->state(fn (): array => [
            'status' => AttendanceStatus::Present,
            'auto_status' => AttendanceStatus::Present,
            'first_joined_at' => now(),
            'last_ping_at' => now(),
            'stay_seconds' => 3600,
        ]);
    }

    public function late(): static
    {
        return $this->state(fn (): array => [
            'status' => AttendanceStatus::Late,
            'auto_status' => AttendanceStatus::Late,
            'first_joined_at' => now(),
            'last_ping_at' => now(),
            'stay_seconds' => 900,
        ]);
    }
}
