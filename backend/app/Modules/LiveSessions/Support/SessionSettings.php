<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * The one place the phase's numbers come from.
 *
 * FR-021أ forbids hard-coding the ladder's thresholds, so no Action may reach
 * for config() directly. Every value is a `platform_settings` row an operator
 * edits from the panel, falling back to config/sessions.php.
 *
 * The ratio-based values are resolved against a specific session's length here
 * rather than at each call site: "half the session" is one rule, and four
 * copies of `duration * ratio` are four chances to round it differently.
 */
class SessionSettings
{
    public function timezone(): string
    {
        return (string) PlatformSettings::get('sessions.timezone', 'Asia/Qatar');
    }

    public function graceMinutes(): int
    {
        return (int) PlatformSettings::get('sessions.grace_minutes', 5);
    }

    public function cancellationWindowMinutes(): int
    {
        return (int) PlatformSettings::get('sessions.cancellation_window_minutes', 1440);
    }

    public function joinWindowMinutes(): int
    {
        return (int) PlatformSettings::get('sessions.join_window_minutes', 15);
    }

    public function presenceIntervalSeconds(): int
    {
        return (int) PlatformSettings::get('sessions.presence_interval_seconds', 30);
    }

    /**
     * The most one ping may add to a stay.
     *
     * Twice the interval: one missed beat is tolerated, an hour of disconnection
     * is not credited as attendance when the browser comes back (FR-024).
     */
    public function maxPingCreditSeconds(): int
    {
        return $this->presenceIntervalSeconds() * 2;
    }

    public function attendanceEditWindowHours(): int
    {
        return (int) PlatformSettings::get('sessions.attendance_edit_window_hours', 48);
    }

    public function reportDelayMinutes(): int
    {
        return (int) PlatformSettings::get('sessions.report_delay_minutes', 15);
    }

    public function recordingMaxAttempts(): int
    {
        return (int) config('sessions.recording_max_attempts', 5);
    }

    /** How long after the start a seat with no ping becomes Absent (FR-021أ). */
    public function absenceThresholdSeconds(ClassSession $session): int
    {
        return $this->ratioOfDuration($session, 'sessions.absence_threshold_ratio', 0.5);
    }

    /** How long a student must stay to count as Present rather than Late. */
    public function requiredStaySeconds(ClassSession $session): int
    {
        return $this->ratioOfDuration($session, 'sessions.required_stay_ratio', 0.5);
    }

    /** How long the teacher must stay for the session to count as delivered. */
    public function teacherRequiredStaySeconds(ClassSession $session): int
    {
        return $this->ratioOfDuration($session, 'sessions.teacher_required_stay_ratio', 0.8);
    }

    private function ratioOfDuration(ClassSession $session, string $key, float $default): int
    {
        $ratio = (float) PlatformSettings::get($key, $default);

        return (int) round($session->duration_minutes * 60 * $ratio);
    }
}
