<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Shared\Actions\Action;
use App\Shared\Contracts\EnrollmentDirectory;
use DomainException;

/**
 * ٠٣٥ — «اعذرْ غيابَه»: العذرُ الماليُّ، مكتوباً قبلَ أن يُقفَلَ البابُ عليه.
 *
 * ⛔ THIS EXISTS BECAUSE `AttendanceStatus::Excused` ARRIVES TOO LATE TO MEAN
 * ANYTHING FINANCIALLY. It has exactly one writer in `app/` —
 * `OverrideAttendance` — and that Action's window opens at
 * `ends_at + attendanceEditWindowHours`, which is AFTER `CloseClassSession` has
 * frozen the verdict and the charge has run. So the fourth row of FR-008د had
 * no door at all until this file.
 *
 * ⚠️ AND IT IS A FACT ABOUT THE BOOKING, NOT ABOUT THE REGISTER. The register
 * answers «did they attend»; this answers «is this seat exempt from the
 * charge». One word, two meanings, two columns — and `attendances.status` is
 * deliberately left alone here, so the teacher's pastoral mark and the
 * platform's money never become the same switch. The first developer to unify
 * them in good faith breaks one of the two doors, which is why
 * `ExcusedTwoMeaningsTest` exists.
 *
 * ⚠️ REFUSED ONCE THE ROOM HAS CLOSED. After that the verdict is frozen and the
 * credit is gone; an excuse accepted then has to REVERSE a charge, which is a
 * different act with a different permission — the elevated arm in
 * `OverrideAttendance`.
 *
 * ⚠️ AND THE STUDENT IS PROVED TO BE THIS TEACHER'S BEFORE ANYTHING IS WRITTEN.
 * NFR-001أ forbids a teacher learning anything about somebody with no active
 * enrolment in their own workspace, so a bare uuid parameter is an identity
 * probe: pass any user's and the response comes back carrying their name.
 * `CreateFreezePeriod` asks `EnrollmentDirectory` the same question for the same
 * reason.
 */
class ExcuseBooking extends Action
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    public function handle(SessionBooking $booking, User $actor): SessionBooking
    {
        $session = $booking->classSession;

        if ($session === null) {
            throw new DomainException('الحصة المرتبطة بهذا الحجز غير موجودة.');
        }

        if ($session->room_closed_at !== null) {
            throw new DomainException('أُقفلت الحصة، فلم يعد العذر يمنع خصمها.');
        }

        $student = $booking->student;

        if ($student === null || ! $this->enrollments->hasActiveEnrollmentInWorkspace(
            $student,
            (int) $session->workspace_id,
        )) {
            throw new DomainException('هذا الطالب ليس من طلابك.');
        }

        // Not fillable: it exempts a seat from a charge, so it is written by this
        // Action alone and never by whatever array reaches a `firstOrCreate`
        // next year. Never re-stamped either — a second acceptance is not a
        // second decision, and the timestamp is the record of WHEN, which is
        // what makes it arguable afterwards.
        if ($booking->excused_at === null) {
            $booking->forceFill([
                'excused_at' => now(),
                'excused_by_user_id' => $actor->getKey(),
            ])->save();
        }

        return $booking;
    }
}
