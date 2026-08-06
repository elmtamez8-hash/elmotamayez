<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use DateTimeInterface;

/**
 * The rung a seat lands on, given when its owner first showed up and how long
 * they stayed (FR-021).
 *
 * Pure: no database, no clock of its own. That is what lets SC-020 test all four
 * positions directly instead of staging four sessions and waiting.
 *
 * Every threshold comes from SessionSettings, because FR-021أ forbids hard-coding
 * them — an operator changes the grace period from the panel, not by shipping.
 */
class AttendanceLadder
{
    public function __construct(
        private readonly SessionSettings $settings,
    ) {}

    public function statusFor(
        ClassSession $session,
        ?DateTimeInterface $firstJoinedAt,
        int $staySeconds,
    ): AttendanceStatus {
        if ($firstJoinedAt === null) {
            return AttendanceStatus::Absent;
        }

        // Measured from the SCHEDULED start, not from when the room happened to
        // open: a teacher who opens ten minutes late must not push every
        // student's grace period out with them.
        if ($firstJoinedAt > $session->absenceThresholdAt()) {
            // Arrived after the threshold. They are already Absent by then and
            // stay so — showing up at the very end is not attendance, and
            // flipping it automatically would make the threshold meaningless
            // (FR-021ج).
            return AttendanceStatus::Absent;
        }

        if ($firstJoinedAt > $session->graceEndsAt()) {
            return AttendanceStatus::Late;
        }

        // On time, but did they stay? A student who joins at the bell and leaves
        // after two minutes was present for the register and absent for the
        // lesson; Late is the honest middle.
        return $staySeconds >= $this->settings->requiredStaySeconds($session)
            ? AttendanceStatus::Present
            : AttendanceStatus::Late;
    }

    /** Whether the teacher's own presence satisfies FR-056's stay condition. */
    public function teacherStayed(ClassSession $session, int $staySeconds): bool
    {
        return $staySeconds >= $this->settings->teacherRequiredStaySeconds($session);
    }
}
