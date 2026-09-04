<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\PlatformRole;
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
        /*
        | ⚠️ Spec 025 · FR-004 — `CreateWorkspace` refuses an owner who is not a
        | teacher, and `User::factory()` does not set `platform_role` at all. This
        | helper is used in 317 test files, so the default belongs here rather
        | than in each of them; a case that deliberately wants another role passes
        | it in `$ownerAttrs` and gets the refusal it is asking for.
        */
        $owner = User::factory()->create([
            'platform_role' => PlatformRole::Teacher,
            ...$ownerAttrs,
        ]);

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
     * Create a workspace owned by an existing user.
     *
     * ⚠️ Spec 025 · FR-008 — «additional» is no longer possible: one owner holds
     * exactly one workspace, guarded by the Action AND by a unique index. This
     * helper survives for the case it is still used for, an existing user who
     * owns nothing yet, and it stamps the teacher role for the same reason
     * `createWorkspaceWithOwner()` does.
     */
    protected function addOwnedWorkspace(User $owner, string $name): Workspace
    {
        if ($owner->platform_role !== PlatformRole::Teacher) {
            $owner->forceFill(['platform_role' => PlatformRole::Teacher])->save();
        }

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

        $user->forceFill(['last_workspace_id' => $workspace->getKey()])->save();

        // Set team context and assign the spatie role.
        app(WorkspaceContext::class)->set($workspace);
        $user->assignRole($role);

        return $user->refresh();
    }

    /**
     * Drop any cached workspace resolution so the next query runs as an anonymous
     * visitor would.
     *
     * WorkspaceContext is an application-wide singleton that freezes its answer on
     * the first id() call, so a test that sets up tenant data and then exercises a
     * public route would otherwise carry that resolution into the "guest" request.
     */
    protected function asGuest(): void
    {
        app()->forgetInstance(WorkspaceContext::class);
        app()->instance(WorkspaceContext::class, new WorkspaceContext);
    }

    /**
     * Set the current workspace context for the given user (simulates middleware).
     */
    protected function setCurrentWorkspace(Workspace $workspace, User $user): void
    {
        // ⚠️ forceFill, NOT update(): `last_workspace_id` is in User's `$guarded`,
        // so the mass-assigned form here has been a silent no-op — the helper
        // claimed to simulate the middleware while writing nothing at all, and
        // every test that reads the column back got null.
        $user->forceFill(['last_workspace_id' => $workspace->getKey()])->save();
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
