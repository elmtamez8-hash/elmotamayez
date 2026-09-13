<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\AttendanceSource;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Events\AttendanceOverridden;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SessionSeatCharges;
use App\Shared\Contracts\SessionUnitReversal;
use DomainException;

/**
 * A teacher marking a student by hand.
 *
 * The exception, not the source (FR-022). Three things make it one:
 *
 *  - `auto_status` is untouched, so the register always shows what the system
 *    concluded next to what a person decided (FR-025). A record that hides
 *    having been edited gets trusted more than it has earned.
 *  - who, when and why are all stored. "Present" with no author is a claim
 *    nobody owns.
 *  - it expires. Past the edit window this refuses and needs a higher
 *    administrative permission (FR-022ب) — a register that stays editable
 *    forever is not a record of anything.
 *
 * ⛔ AND SINCE 035 IT HAS A FINANCIAL ARM, WITH ONE DIRECTION. The owner's
 * decision of 2026-09-13: an excuse accepted before the room closes EXEMPTS
 * (`ExcuseBooking`), and one accepted inside this window REVERSES. Marking
 * somebody `Excused` after their seat was charged gives the credit back.
 *
 * ⚠️ THE RULE IS THE DIRECTION, AND IT IS WRITTEN WHERE IT IS READ: a mark made
 * here EXEMPTS AND REVERSES, and never CHARGES. A teacher marking somebody
 * `Present` after the fact must not create a debt — that would be a person
 * typing a number that decides their own pay, which is the whole reason
 * `credit_verdict_at` is frozen from the stay rather than from `status`. This
 * is an explicit amendment to 014 · FR-004, written into `spec.md` in the same
 * change.
 */
class OverrideAttendance extends Action
{
    public function __construct(
        private readonly SessionSettings $settings,
        private readonly SessionSeatCharges $charges,
        private readonly SessionUnitReversal $units,
    ) {}

    public function handle(
        Attendance $attendance,
        AttendanceStatus $status,
        User $actor,
        string $reason,
        bool $hasElevatedPermission = false,
    ): Attendance {
        $session = $attendance->classSession;

        if ($session === null) {
            throw new DomainException('الحصة المرتبطة بهذا السجلّ غير موجودة.');
        }

        $deadline = $session->ends_at->copy()->addHours($this->settings->attendanceEditWindowHours());

        if (now()->greaterThan($deadline) && ! $hasElevatedPermission) {
            throw new DomainException('انتهت مهلة تعديل الحضور لهذه الحصة.');
        }

        if (trim($reason) === '') {
            throw new DomainException('اذكر سبب التعديل.');
        }

        /*
        | 035 — the reversal arm, and the four conditions on it are all load-bearing.
        |
        |  · `Excused` only. Exempting is the one direction a human mark may move
        |    money in; charging is not, for the reason in the class docblock.
        |  · a seat that was ACTUALLY CHARGED (`credit_verdict_at` stamped).
        |    Null means judged and exempt, and there is nothing to give back.
        |  · elevated permission only. Inside the window an ordinary teacher may
        |    still correct the register; moving a credit back is a different act.
        |  · through the contract, never an import. `ContextIsolationTest` allows
        |    this module exactly one literal mention of the billing namespace,
        |    counted, and comments are stripped before the scan.
        */
        $reversing = $status === AttendanceStatus::Excused
            && $attendance->credit_verdict_at !== null
            && $hasElevatedPermission;

        $student = $attendance->student;

        if ($reversing && $student === null) {
            /*
            | ⛔ NEVER THE ACTOR IN THE STUDENT'S PLACE, which the first draft
            | wrote as a `?? $actor` fallback. `attendances.student_user_id`
            | carries no foreign key, so a deleted account really does leave the
            | row behind (the `ListStudentBalances` defect) — and substituting
            | the teacher there does not fail, it reverses a charge against the
            | WRONG PERSON'S balance, or silently finds nothing and returns
            | false while the register says the excuse was accepted.
            */
            throw new DomainException('لا يمكن ردُّ حصّةِ سجلٍّ لا يخصُّ حساباً قائماً.');
        }

        $reversed = $reversing
            && $this->charges->reverse(
                $student,
                (int) $attendance->class_session_id,
                $reason,
            );

        /*
        | ⛔ AND THE TEACHER'S SIDE OF THE SAME HOUR, or the platform pays for
        | the excuse out of its own pocket (T031). Two contracts rather than one
        | because they are two contexts with no key between them — the credit
        | lives in billing, the unit in settlement, and `ContextIsolationTest`
        | fails the build over anything that joins them.
        */
        if ($reversed) {
            $this->units->reverseSeat(
                (int) $attendance->class_session_id,
                (int) $attendance->student_user_id,
                $reason,
            );
        }

        $attendance->forceFill([
            'status' => $status,
            'source' => AttendanceSource::Manual,
            // auto_status deliberately left as it was.
            'overridden_by' => $actor->getKey(),
            'overridden_at' => now(),
            'override_reason' => $reason,
            // Cleared with the reversal, so the content gate and the register
            // give one answer: the seat is no longer charged, so its content is
            // locked again until the student consents to pay for it.
            'credit_verdict_at' => $reversed ? null : $attendance->credit_verdict_at,
        ])->save();

        AttendanceOverridden::dispatch($attendance);

        return $attendance;
    }
}
