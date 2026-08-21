<?php

declare(strict_types=1);

namespace App\Modules\Media\Listeners;

use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaLimits;
use App\Shared\Contracts\EnrollmentDirectory;
use Carbon\CarbonImmutable;

/**
 * How long a departed teacher's recordings live (spec 013 · FR-036).
 *
 * ⚠️ THE DATE IS DERIVED FROM OTHER PEOPLE'S RIGHTS, NOT FROM THE TEACHER'S EXIT.
 * FR-036 asks that the retention "respect the rights of the students who appear in
 * them" — so the question is not "when did this teacher leave" and not "when was
 * this lesson taught", it is "when does the last person who PAID for a seat in
 * that room lose their access". Deleting on the exit date would take a recording
 * away from a student whose term still has two months to run, which is FR-035's
 * second half broken by the requirement immediately after it.
 *
 * ⚠️ AND IT SETS A DATE RATHER THAN DELETING ANYTHING. The only deletion path in
 * this module is the retention sweep — provider first, `archived_at` stamped, the
 * batch event that archives the lesson and resyncs each course once. A listener
 * that deleted directly would be a second one, and the first thing it would skip
 * is the lesson sitting in every enrolled student's course tree.
 */
class SetDepartedTeacherRetention
{
    public function __construct(private readonly EnrollmentDirectory $enrollments) {}

    public function handle(TeacherOffboardingCompleted $event): void
    {
        $workspaceId = (int) $event->offboarding->workspace_id;

        $lastAccess = $this->lastPaidAccessIn($workspaceId);

        MediaAsset::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereNull('archived_at')
            ->whereNull('retain_until')
            ->update(['retain_until' => $lastAccess, 'updated_at' => now()]);
    }

    /**
     * The last moment anybody in this workspace still holds what they paid for.
     *
     * ⚠️ A NULL `expires_at` MEANS ACCESS THAT DOES NOT EXPIRE, NOT ACCESS THAT
     * ENDED. Treating null as "no date, so the earliest" would delete the
     * recordings of every ordinary enrolment on the platform the day its teacher
     * left — enrolments are open-ended by default here. Where any such enrolment
     * exists the floor is the category's own retention measured from today, which
     * is the promise every other student on the platform already has.
     */
    private function lastPaidAccessIn(int $workspaceId): CarbonImmutable
    {
        $floor = CarbonImmutable::now()->addDays(MediaLimits::departedTeacherFloorDays());

        [$latest, $hasOpenEnded] = $this->enrollments->accessHorizonFor($workspaceId);

        if ($hasOpenEnded || $latest === null) {
            return $floor;
        }

        // The later of the two: a term running past the floor keeps its recordings
        // to the end of the term, and one ending sooner still gets the floor.
        return $latest->greaterThan($floor) ? $latest : $floor;
    }
}
