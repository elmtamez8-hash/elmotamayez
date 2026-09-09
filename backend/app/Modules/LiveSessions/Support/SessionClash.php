<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * «Is this hour free for this teacher?» — one spelling, two doors.
 *
 * ⚠️ AND THE SECOND DOOR HAD NO GUARD AT ALL. `ScheduleClassSession` refused an
 * overlap and a freeze period from the day it was written; `UpdateClassSession`
 * moved `starts_at` with neither check. So the rule held while a session was
 * being created and evaporated the moment one was edited — which is precisely
 * how one group's Saturday lands on top of another group's Saturday, with two
 * rooms of students told to turn up to the same hour and nothing anywhere saying
 * so. The clash is per TEACHER and not per group, because a teacher cannot be in
 * two rooms at once whichever groups they belong to.
 *
 * ⚠️ HALF-OPEN ON PURPOSE. A session ending at 15:00 and one starting at 15:00
 * do not overlap; treating them as a clash would block back-to-back teaching,
 * which is how a full day is actually taught.
 *
 * ⚠️ AND THE ROW BEING EDITED IS EXCLUDED BY ID, NOT BY TIME. Without that, any
 * edit to a session — its title, its seat count — is refused as an overlap with
 * itself the moment the times are re-checked.
 */
final class SessionClash
{
    public static function assertFree(
        int $teacherProfileId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $ignoreSessionId = null,
    ): void {
        $clash = ClassSession::query()
            ->where('teacher_profile_id', $teacherProfileId)
            ->when($ignoreSessionId !== null, fn ($query) => $query->whereKeyNot($ignoreSessionId))
            ->whereNotIn('status', [ClassSessionStatus::Cancelled, ClassSessionStatus::Suspended])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($clash) {
            throw new DomainException('لديك حصة أخرى في هذا الوقت.');
        }
    }

    public static function assertNotFrozen(CarbonImmutable $startsAt): void
    {
        if (FreezePeriod::query()->covering($startsAt)->exists()) {
            throw new DomainException('لا يمكن جدولة حصة داخل فترة تجميد.');
        }
    }
}
