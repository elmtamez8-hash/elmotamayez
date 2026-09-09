<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Resources;

use App\Modules\LiveSessions\Actions\SummariseChildAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A child's attendance over a window, as the guardian's card reads it.
 *
 * Four counts, their total, the window they were measured over, and the rate
 * derived from them — sent WITH the four rather than instead of them, so the
 * screen can show the working and a reader can check the number against the
 * register (029 · FR-018).
 *
 * ⚠️ NO NAME, NO SESSION, NO NOTE. The guardian asked how their child is doing,
 * not who else was in the room: the register itself is `ATTENDANCE_VIEW`, and a
 * per-session breakdown here would be that screen reached through a summary.
 */
class ChildAttendanceSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{window_days: int, present: int, late: int, absent: int, excused: int, total: int, rate_pct: int|null} $summary */
        $summary = $this->resource;

        return [
            'window_days' => $summary['window_days'],
            'present' => $summary['present'],
            'late' => $summary['late'],
            'absent' => $summary['absent'],
            'excused' => $summary['excused'],
            'total' => $summary['total'],
            // `null` is a state and not a missing number: nothing has been
            // recorded yet. See {@see SummariseChildAttendance} for why it is
            // never zero.
            'rate_pct' => $summary['rate_pct'],
        ];
    }
}
