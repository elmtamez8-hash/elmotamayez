<?php

declare(strict_types=1);

namespace App\Modules\Learning\Listeners;

use App\Models\User;
use App\Modules\Learning\Actions\RemoveMember;
use App\Modules\Learning\Models\CohortMembership;
use App\Shared\Events\CourseAccessWithdrawn;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A reversed course order gives back the student's place in the group
 * (owner decision 2026-09-23) — through `RemoveMember`, the one writer, so the
 * seat count and any pending transfer move exactly as a teacher's removal does.
 *
 * ⚠️ NOT ON `SubscriptionEnded`: a renewal lands after the old month ends, and a
 * student who renews must not find their group place gone.
 */
class LeaveCohortsOnOrderReversed implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(private readonly RemoveMember $remove) {}

    public function handle(CourseAccessWithdrawn $event): void
    {
        $actor = User::query()->find($event->reversedByUserId);

        if ($actor === null || $event->courseIds === []) {
            return;
        }

        CohortMembership::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $event->studentUserId)
            ->whereIn('course_id', $event->courseIds)
            ->whereNull('closed_at')
            ->get()
            ->each(fn (CohortMembership $membership) => $this->remove->handle($membership, $actor, 'استُرِدَّ ثمنُ الكورس.'));
    }
}
