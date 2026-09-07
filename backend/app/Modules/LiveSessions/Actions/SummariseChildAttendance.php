<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Actions\Action;

/**
 * One student's attendance over a window, counted once across every teacher.
 *
 * ⚠️ IT CROSSES WORKSPACES ON PURPOSE, AND THE GUARD IS NOT THE SCOPE (029 ·
 * FR-020). `attendances` is workspace-owned, and a child studying with three
 * teachers has ONE attendance record — a per-workspace answer would tell a
 * parent their child attended 100% while a second teacher's register says
 * otherwise, and neither number would be the truth they asked for. So the scope
 * is dropped deliberately here, exactly as {@see GetStudentSchedule} drops it
 * for the same person's timetable.
 *
 * What replaces it is the STUDENT'S OWN KEY plus, at the door, the guardian
 * permission: a query keyed on one person's id is strictly narrower than a
 * workspace filter, not wider. `excludingHost()` is not needed and would be
 * misleading — a query keyed on the child never picks up the teacher's own
 * delivery row.
 *
 * ⚠️ AND THE SCOPE IS DROPPED ON BOTH SIDES. The session subquery carries its
 * own bypass: left scoped, it would resolve only the reader's fallback
 * workspace and silently halve the count — the shape spec 024 found five times
 * in one chain, where a resolved-but-wrong context is a denial wearing a 200.
 */
class SummariseChildAttendance extends Action
{
    /** The widest window a caller may ask for, so one request cannot walk a life. */
    public const MAX_DAYS = 365;

    /**
     * @return array{
     *     window_days: int,
     *     present: int,
     *     late: int,
     *     absent: int,
     *     excused: int,
     *     total: int,
     *     rate_pct: int|null
     * }
     */
    public function handle(User $student, int $days = 30): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));

        /*
         | ⚠️ THE UPPER BOUND IS THE START OF TOMORROW, NEVER `<= today`.
         | `starts_at` is a TIMESTAMP and the bound is a DATE, so `<= today`
         | binds midnight and drops every lesson taught on the last day of the
         | window — the boundary that has already cost `FreezePeriod::covering()`
         | and a settlement close their own fixes.
         */
        $from = now()->subDays($days - 1)->startOfDay();
        $until = now()->addDay()->startOfDay();

        $sessions = ClassSession::query()
            ->withoutWorkspaceScope()
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $until)
            ->select('id');

        // One aggregate, not four counts and not a walk in PHP: the register of
        // a year is thousands of rows and the answer is four numbers.
        $tally = Attendance::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $student->getKey())
            ->whereIn('class_session_id', $sessions)
            ->groupBy('status')
            ->selectRaw('status, count(*) as tally')
            ->pluck('tally', 'status');

        $of = static fn (AttendanceStatus $status): int => (int) ($tally[$status->value] ?? 0);

        $present = $of(AttendanceStatus::Present);
        $late = $of(AttendanceStatus::Late);
        $absent = $of(AttendanceStatus::Absent);
        $excused = $of(AttendanceStatus::Excused);
        $total = $present + $late + $absent + $excused;

        return [
            'window_days' => $days,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'excused' => $excused,
            'total' => $total,
            /*
             | ⚠️ ABSENCE ALONE SUBTRACTS, AND AN EXCUSAL COUNTS AS ATTENDED —
             | the same rule `BookingEligibility` already applies: an excusal is
             | the teacher's decision that the absence is not held against the
             | student, so a rate that penalised it would contradict the register
             | it is derived from.
             |
             | ⚠️ AND ZERO SESSIONS ANSWER `null`, NEVER `0`. «لم تُسجَّلْ حصصٌ
             | بعد» and «غابَ عن كلِّ حصّة» are opposite sentences, and a zero
             | prints the second one over a child who has not started — the
             | trust-score precedent, where a sample below the threshold is null
             | with a band rather than a number nobody earned.
             */
            'rate_pct' => $total === 0 ? null : (int) round((($total - $absent) / $total) * 100),
        ];
    }
}
