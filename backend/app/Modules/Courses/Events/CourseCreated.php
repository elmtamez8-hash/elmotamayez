<?php

declare(strict_types=1);

namespace App\Modules\Courses\Events;

use App\Modules\Courses\Actions\CreateCourse;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A course row was written by {@see CreateCourse}.
 *
 * Its first reader is Community: a CONFINED assistant who creates a course must
 * find it inside their own scope on the very next request, or every edit of the
 * course they just made is refused (owner decision 2026-09-29). Courses does not
 * know that assistants or scopes exist, so it announces and Community decides.
 *
 * ⚠️ DISPATCHED INSIDE THE TRANSACTION THAT WRITES THE COURSE, on purpose: the
 * scope row and the course are one unit, so an assistant never holds a course
 * they cannot touch. A synchronous listener therefore shares that transaction —
 * and any QUEUED listener added later must be `ShouldQueueAfterCommit`, or its
 * job is pushed before the course is visible to the worker (the rule
 * `QueuedListenersAfterCommitTest` and `RolledBackEventQueuesNothingTest` police).
 *
 * Plain ids, no model: nothing here is serialised, and the listener reads what it
 * needs with its own tenant key named explicitly.
 */
class CourseCreated
{
    use Dispatchable;

    public function __construct(
        public readonly int $courseId,
        public readonly int $workspaceId,
        public readonly int $creatorUserId,
    ) {}
}
