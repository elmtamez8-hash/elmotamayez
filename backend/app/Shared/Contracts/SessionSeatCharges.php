<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;

/**
 * ٠٣٥ — نقضُ خصمٍ وقعَ بالفعل، من الجانبِ الذي لا يعرفُ الفوترة.
 *
 * ⛔ THE EXCEPTIONAL DOOR OF THE OWNER'S DECISION OF 2026-09-13. The ordinary
 * one is `session_bookings.excused_at`, written before the room closes, and it
 * simply exempts. This is what happens when the teacher accepts the excuse
 * AFTERWARDS — inside `OverrideAttendance`'s edit window, which opens at
 * `ends_at + attendanceEditWindowHours`, i.e. after the verdict was frozen and
 * the credit was already spent. By then an excuse cannot exempt; it can only
 * REVERSE.
 *
 * ⚠️ A CONTRACT AND NOT A CALL, because `ContextIsolationTest` allows
 * `Modules/LiveSessions/` exactly ONE mention of the billing namespace — a
 * single literal import, counted at `:685` — and comments are stripped before
 * the scan, so an explanation does not buy a line.
 *
 * ⚠️ AND THE REVERSING ENTRY CARRIES ITS OWN SOURCE. Sharing the original's
 * idempotency key means the ledger reads the reversal as «already recorded»,
 * writes ZERO rows, hands back the ORIGINAL on the read-back, and the caller
 * reports success while the credit is never returned. This repository has paid
 * for that exact shape once already, in `award_entries`.
 *
 * ⚠️ THE TEACHER'S UNIT IS REVOKED WITH IT, and NOT from here. That belongs to
 * Settlement, which reaches it through the `AttendanceOverridden` event it is
 * already allowed to consume — and without it the platform pays for an excuse
 * out of its own pocket.
 */
interface SessionSeatCharges
{
    /**
     * Give back the credit this student was charged for this session.
     *
     * @return bool whether a charge was found and reversed — false means there
     *              was nothing to give back, which is the ordinary answer for a
     *              seat that was exempt in the first place
     */
    public function reverse(User $student, int $classSessionId, string $reason): bool;
}
