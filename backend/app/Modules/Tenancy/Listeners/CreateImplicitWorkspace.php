<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Marketplace\Events\TeacherRegistered;
use App\Modules\Tenancy\Actions\CreateWorkspace;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;

/**
 * Spec 025 · FR-001 — the workspace is born with the account, and nobody sees it.
 *
 * ⚠️ A PLAIN CLASS WITH NO `ShouldQueue`, deliberately. FR-003 requires the
 * account and the workspace to succeed together or fail together, which only
 * holds while this runs inside `RegisterTeacher`'s own transaction — a queued
 * listener would commit the account and leave the workspace to a worker that may
 * never run, producing exactly the account-with-no-workspace this spec exists to
 * abolish. The precedent is `SeedDefaultRoles`, synchronous for the same reason.
 *
 * ⚠️ AND A SYNCHRONOUS LISTENER IS A SINGLE POINT OF FAILURE FOR EVERYTHING
 * DISPATCHED AFTER IT. Here that is the intent — a throw must roll the whole
 * registration back — but it means the exposure of this line is *the ability to
 * register at all*, not data integrity. Atomicity holds either way.
 *
 * The name comes from the teacher's own name (FR-005): no field in the form, no
 * type to choose. `User::name` is an accessor over `first_name`/`last_name`, and
 * the model in hand carries both.
 */
class CreateImplicitWorkspace
{
    public function __construct(
        private readonly CreateWorkspace $createWorkspace,
    ) {}

    public function handle(TeacherRegistered $event): void
    {
        $this->createWorkspace->handle(
            new CreateWorkspaceDTO(
                name: $event->teacher->name,
                type: 'teacher',
            ),
            $event->teacher,
        );
    }
}
