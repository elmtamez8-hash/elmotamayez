<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Actions\EnrollStudent;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Actions\CreateWorkspace;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;

trait WithWorkspace
{
    /**
     * Create a workspace and its owner (with tenant-owner role), returning both.
     *
     * @param  array<string, mixed>  $workspaceAttrs
     * @param  array<string, mixed>  $ownerAttrs
     * @return array{0: Workspace, 1: User}
     */
    protected function createWorkspaceWithOwner(array $workspaceAttrs = [], array $ownerAttrs = []): array
    {
        $owner = User::factory()->create($ownerAttrs);

        $workspace = app(CreateWorkspace::class)->handle(
            CreateWorkspaceDTO::fromArray(array_merge([
                'name' => 'Test Academy',
                'type' => 'academy',
                'slug' => 'test-academy-'.Str::random(6),
            ], $workspaceAttrs)),
            $owner,
        );

        return [$workspace, $owner->refresh()];
    }

    /**
     * Create an additional workspace owned by an existing user.
     */
    protected function addOwnedWorkspace(User $owner, string $name): Workspace
    {
        return app(CreateWorkspace::class)->handle(
            CreateWorkspaceDTO::fromArray([
                'name' => $name,
                'type' => 'academy',
                'slug' => Str::slug($name).'-'.Str::random(6),
            ]),
            $owner,
        );
    }

    /**
     * Create a user and add them as a member of the given workspace with the given role.
     */
    protected function addWorkspaceMember(Workspace $workspace, string $role = Roles::STUDENT, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $workspace->members()->attach($user->getKey(), [
            'role' => $role,
            'joined_at' => now(),
        ]);

        $user->update(['last_workspace_id' => $workspace->getKey()]);

        // Set team context and assign the spatie role.
        app(WorkspaceContext::class)->set($workspace);
        $user->assignRole($role);

        return $user->refresh();
    }

    /**
     * Set the current workspace context for the given user (simulates middleware).
     */
    protected function setCurrentWorkspace(Workspace $workspace, User $user): void
    {
        $user->update(['last_workspace_id' => $workspace->getKey()]);
        app(WorkspaceContext::class)->set($workspace);
    }

    /**
     * Create an enrollment for a student in a course via the EnrollStudent action,
     * which fires the EnrollmentCreated event (notifications, etc.).
     */
    protected function createEnrollment(Workspace $workspace, Course $course, User $student, string $source = 'manual'): Enrollment
    {
        app(WorkspaceContext::class)->set($workspace);

        return app(EnrollStudent::class)->handle($course, $student, $source);
    }
}
