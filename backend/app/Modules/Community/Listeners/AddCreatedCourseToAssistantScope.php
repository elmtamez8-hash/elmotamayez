<?php

declare(strict_types=1);

namespace App\Modules\Community\Listeners;

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Support\EloquentAssistantScopeDirectory;
use App\Modules\Courses\Events\CourseCreated;
use App\Shared\Contracts\AssistantScopeDirectory;

/**
 * A CONFINED assistant who creates a course keeps it: the new course joins their
 * scope (owner decision 2026-09-29). Before this, the course was born outside the
 * confinement and every edit of it answered «هذا الكورس خارج نطاق عملك.».
 *
 * ⛔ AN UNCONFINED CREATOR IS NEVER GIVEN A ROW. An empty scope means EVERY
 * course (see the `assistant_scopes` migration), so writing one row for an
 * assistant with none would CONFINE them to the single course they just made —
 * the exact opposite of the decision. Teachers, owners and anybody without a live
 * assignment here are not assistants and are left alone for the same reason.
 *
 * ⚠️ SYNCHRONOUS, NEVER QUEUED: the assistant's very next request edits the
 * course, and a queued write could lose that race. It runs inside
 * `CreateCourse`'s transaction, so the course and its scope row stand or fall
 * together.
 */
class AddCreatedCourseToAssistantScope
{
    public function __construct(private readonly AssistantScopeDirectory $directory) {}

    public function handle(CourseCreated $event): void
    {
        // The directory's own predicate: this workspace, this person, live only.
        $assignment = AssistantAssignment::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $event->workspaceId)
            ->where('assistant_user_id', $event->creatorUserId)
            ->active()
            ->first();

        if (! $assignment instanceof AssistantAssignment || ! $assignment->scopes()->exists()) {
            return;
        }

        $assignment->scopes()->firstOrCreate(['course_id' => $event->courseId]);

        /*
        | ⚠️ THE INTERFACE IS INJECTED, NOT THE CLASS. The binding is `scoped()`, so
        | the interface resolves to the instance this request's policies already
        | memoised; asking for the concrete class would build a fresh one and bust
        | an empty memo while the real one kept the old list.
        */
        if ($this->directory instanceof EloquentAssistantScopeDirectory) {
            $this->directory->forgetScopeOf($event->creatorUserId, $event->workspaceId);
        }
    }
}
