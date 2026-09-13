<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Listeners;

use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Events\AttendanceOverridden;
use App\Modules\Settlement\Actions\ReverseTeachingUnit;
use App\Modules\Settlement\Models\TeachingUnit;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ٠٣٥ — عذرٌ قُبِلَ بعدَ القفلِ يستردُّ حصّةَ الطالبِ **ويُسقِطُ أجرَ المدرّسِ عنها**.
 *
 * ⛔ WITHOUT THIS THE PLATFORM PAYS FOR THE EXCUSE OUT OF ITS OWN POCKET. The
 * student's credit is given back by `SessionSeatCharges::reverse()`; if the
 * teacher keeps the unit that same seat earned, the two sides of one hour stop
 * adding up and the difference is the platform's. The owner's decision of
 * 2026-09-13 names both halves as one act.
 *
 * ⚠️ IT HANGS OFF A LiveSessions EVENT AND NOT A Payments ONE, and that is the
 * only shape available. `ContextIsolationTest` forbids inside
 * `Modules/Settlement/` every bare BASENAME of a file in
 * `Modules/Payments/Events/` — so a `CreditChargeReversed` event would fail the
 * build on the string alone, with the comment explaining it stripped before the
 * scan. `AttendanceOverridden` is this module's own side of the bridge, in the
 * same direction `SessionDelivered` already crosses.
 *
 * ⚠️ QUEUED AND AFTER COMMIT, like its sibling `AccrueUnitsOnDelivery` — which
 * is a plain class no longer for a measured reason: ONE synchronous listener
 * among queued ones is a single point of failure for everything dispatched
 * after it, and a throw here would propagate back into `OverrideAttendance` and
 * kill the register correction the teacher was actually making.
 *
 * ⚠️ AND IT RE-READS THE ROW RATHER THAN TRUSTING THE EVENT'S MODEL. The
 * reversal inside `OverrideAttendance` clears `credit_verdict_at` on the same
 * instance the event carries, so the attribute this listener needs is already
 * gone from it. `reversal_of_id` on the unit is the durable record of whether
 * this has already run, which is what makes a replay harmless.
 */
class ReverseUnitOnExcusedOverride implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(private readonly ReverseTeachingUnit $reverse) {}

    public function handle(AttendanceOverridden $event): void
    {
        $attendance = $event->attendance;

        if ($attendance->status !== AttendanceStatus::Excused) {
            return;
        }

        $unit = TeachingUnit::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $attendance->class_session_id)
            ->where('student_user_id', $attendance->student_user_id)
            ->where('reversal_of_id', TeachingUnit::NOT_A_REVERSAL)
            ->first();

        if ($unit === null) {
            return;
        }

        /*
        | ⚠️ THE REVERSAL IS LOOKED FOR BY ITS OWN KEY, and that check belongs
        | here rather than in the Action. `ReverseTeachingUnit` refuses an
        | original that IS a reversal or that carries the `Reversed` status — and
        | it sets neither on the original it reverses, so a replayed event walks
        | straight past both guards and lands on
        | `unique(class_session_id, student_user_id, reversal_of_id)` as an
        | unhandled QueryException. A queue retry then repeats it for ever.
        */
        $alreadyReversed = TeachingUnit::query()
            ->withoutWorkspaceScope()
            ->where('reversal_of_id', $unit->getKey())
            ->exists();

        if ($alreadyReversed) {
            return;
        }

        $this->reverse->handle($unit, 'عذرٌ قُبِلَ بعد إغلاق الحصّة (٠٣٥ · FR-007ب)');
    }
}
