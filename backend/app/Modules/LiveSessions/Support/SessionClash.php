<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

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
 *
 * ⚠️ IT IS A CLAIM, NOT A READ — and until 2026-09-24 it was a read. `exists()`
 * followed by the caller's `create()` is the definition of the race: two tabs
 * accepting two students' private requests for the same Tuesday six o'clock (or
 * two reschedule approvals landing on one hour) both found the hour free, both
 * wrote, and one person was booked into two rooms. The only unique index nearby
 * is per STUDENT, so nothing in the database stopped it.
 *
 * So the check reads `teacher_profiles.schedule_version` FIRST, asks the overlap
 * question, and then claims that exact version with one conditional UPDATE —
 * `StructureVersion::claim()`'s idiom. A second writer who read the same version
 * matches zero rows and is refused; on MySQL its UPDATE waits on the winner's row
 * lock and then re-evaluates the WHERE against the committed value, so the answer
 * is right whatever snapshot the surrounding transaction holds. A counter rather
 * than a unique index because a clash is an OVERLAP of two intervals, which no
 * index can express (the migration says more). Never `lockForUpdate()`: a no-op
 * on SQLite, so a test written around it passes locally and proves nothing.
 *
 * ⚠️ THE CALLER MUST HOLD A TRANSACTION AROUND THIS AND ITS OWN WRITE. The claim's
 * row lock is what keeps the second writer out until the session row commits;
 * released early, the second writer re-reads a free hour.
 *
 * ⚠️ NO RETRY LOOP. Inside one MySQL transaction a re-read returns the same
 * snapshot, so a retry reads the stale version again and loses again. A sentence
 * asking for one more try is the honest answer: the next request starts a fresh
 * transaction, sees the winner's session, and is refused by name.
 */
final class SessionClash
{
    public static function assertFree(
        int $teacherProfileId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $ignoreSessionId = null,
    ): void {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SessionClash::assertFree() claims the calendar and must run inside the caller\'s transaction.');
        }

        // Read BEFORE the overlap question: a writer who lands between the two
        // moves the version, and the claim below then refuses.
        $version = DB::table('teacher_profiles')->where('id', $teacherProfileId)->value('schedule_version');

        $clash = ClassSession::query()
            /*
            | ⚠️ UNSCOPED, because the question is about a PERSON. A teacher can be
            | scheduled from more than one workspace (their own, and an academy
            | that schedules for them — `SchedulableTeachers`), and the scope would
            | AND the reader's own workspace onto it: the academy's Saturday was
            | invisible to the teacher putting their own Saturday on top of it.
            */
            ->withoutWorkspaceScope()
            ->where('teacher_profile_id', $teacherProfileId)
            ->when($ignoreSessionId !== null, fn ($query) => $query->whereKeyNot($ignoreSessionId))
            ->whereNotIn('status', [ClassSessionStatus::Cancelled, ClassSessionStatus::Suspended])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($clash) {
            throw new DomainException('لديك حصة أخرى في هذا الوقت.');
        }

        if ($version === null) {
            // No profile row: nothing to claim. `ScheduleClassSession` refuses
            // this case earlier with its own sentence.
            return;
        }

        $claimed = DB::table('teacher_profiles')
            ->where('id', $teacherProfileId)
            ->where('schedule_version', $version)
            ->update(['schedule_version' => DB::raw('schedule_version + 1')]);

        if ($claimed !== 1) {
            throw new DomainException('تغيّر جدول المدرّس في هذه اللحظة من جهة أخرى. أعد المحاولة.');
        }
    }

    public static function assertNotFrozen(CarbonImmutable $startsAt): void
    {
        if (FreezePeriod::query()->covering($startsAt)->exists()) {
            throw new DomainException('لا يمكن جدولة حصة داخل فترة تجميد.');
        }
    }
}
