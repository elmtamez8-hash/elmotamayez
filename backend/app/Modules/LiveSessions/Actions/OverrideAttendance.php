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
 */
class OverrideAttendance extends Action
{
    public function __construct(
        private readonly SessionSettings $settings,
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

        $attendance->forceFill([
            'status' => $status,
            'source' => AttendanceSource::Manual,
            // auto_status deliberately left as it was.
            'overridden_by' => $actor->getKey(),
            'overridden_at' => now(),
            'override_reason' => $reason,
        ])->save();

        AttendanceOverridden::dispatch($attendance);

        return $attendance;
    }
}
