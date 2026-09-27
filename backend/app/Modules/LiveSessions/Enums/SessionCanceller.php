<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Enums;

/**
 * Who called a session off (`class_sessions.cancelled_by`).
 *
 * ⛔ IT EXISTS FOR ONE READER, AND THAT READER IS A TEACHER'S PUBLIC SCORE.
 * Since 2026-09-26 a student who gives back a private hour IN TIME calls the
 * session off through `CancelClassSession` (`CancelBooking::cancelEmptyPrivateSession()`),
 * and `SyncTeacherCountersJob` counted every `cancelled` row in
 * `cancelled_sessions_count` — which `TrustScoreCalculator` reads as the
 * teacher failing to teach. So each student who changed their mind lowered the
 * teacher's marketplace ranking. The counter now skips `Student`.
 *
 * ⚠️ NULL READS AS `Teacher`. Every row cancelled before the column existed was
 * cancelled from the teacher's own door (the student door did not exist until
 * the day before), so the migration writes nothing and the reader treats null as
 * the teacher — backfill-free, and true of the history.
 */
enum SessionCanceller: string
{
    case Teacher = 'teacher';
    case Student = 'student';
    case System = 'system';

    /** Whether this cancellation counts against the teacher's record. */
    public function countsAgainstTeacher(): bool
    {
        return match ($this) {
            self::Teacher => true,
            self::Student, self::System => false,
        };
    }
}
