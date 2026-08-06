<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The heartbeat, and the whole of attendance.
 *
 * The room's client posts here every `presence_interval_seconds`, and the server
 * does the arithmetic. No provider webhook is involved (research §R3) — three
 * things follow from that, and each is a requirement rather than a preference:
 *
 *  - the register is buildable and provable before any broadcast contract is
 *    signed, because none of it passes through a provider;
 *  - a lost message cannot leave someone "in the room" forever in a report that
 *    goes to their guardian — a stopped heartbeat simply stops crediting time,
 *    which is exactly what happened;
 *  - swapping providers later does not rewrite the largest logic in the phase.
 *
 * One line does the accumulating:
 *
 *     stay_seconds += min(now − last_ping_at, 2 × interval)
 *
 * and three required behaviours fall out of it together:
 *
 *  - two devices at once do NOT double the time (FR-024): each ping measures
 *    from the last ping by ANY device, so the sum is wall-clock;
 *  - leaving and returning aggregates into one stay rather than two rows;
 *  - a long disconnection is not credited, because the cap is two intervals —
 *    one missed beat is forgiven, an hour away is not attendance.
 */
class RecordPresencePing extends Action
{
    public function __construct(
        private readonly SessionSettings $settings,
    ) {}

    public function handle(ClassSession $session, User $user): Attendance
    {
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($session, $user, $now): Attendance {
            $attendance = Attendance::query()->firstOrCreate(
                [
                    'class_session_id' => $session->getKey(),
                    'student_user_id' => $user->getKey(),
                ],
                [
                    'workspace_id' => $session->workspace_id,
                    // Absent until a ping says otherwise — the safe direction:
                    // a row that starts Present and is never corrected credits
                    // attendance nobody attended.
                    'status' => AttendanceStatus::Absent,
                    'auto_status' => AttendanceStatus::Absent,
                    'source' => AttendanceSource::Automatic,
                    'stay_seconds' => 0,
                ],
            );

            $credit = $this->creditFor($attendance, $now);

            $attendance->forceFill([
                'first_joined_at' => $attendance->first_joined_at ?? $now,
                'last_ping_at' => $now,
                'stay_seconds' => $attendance->stay_seconds + $credit,
            ])->save();

            return $attendance;
        });
    }

    /**
     * How many seconds this ping is worth.
     *
     * The first ping of a stay is worth nothing: no time has been spent yet, and
     * crediting the interval up front would pay for attendance that has not
     * happened.
     */
    private function creditFor(Attendance $attendance, CarbonImmutable $now): int
    {
        if ($attendance->last_ping_at === null) {
            return 0;
        }

        $elapsed = (int) $attendance->last_ping_at->diffInSeconds($now, false);

        return max(0, min($elapsed, $this->settings->maxPingCreditSeconds()));
    }
}
