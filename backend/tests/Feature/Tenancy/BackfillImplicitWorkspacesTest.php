<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Spec 025 · US4 — the accounts that registered before the workspace was born
 * with them, and the accounts that must not be touched.
 *
 * The migration is instantiated and run directly: `RefreshDatabase` already
 * replayed it against an empty database before any of these fixtures existed, so
 * calling `up()` here is the only way to measure it against real rows. It is also
 * how FR-017 (repeatable) is measured at all — by running it twice.
 */
function runBackfill(): void
{
    $migration = require base_path(
        'app/Modules/Tenancy/Database/Migrations/2026_09_04_000100_backfill_implicit_teacher_workspaces.php'
    );

    $migration->up();
}

/**
 * A teacher who registered before this spec: an application row, no workspace.
 *
 * Their application lives in somebody else's workspace, which is exactly where
 * production put them — that container is what the NEXT migration empties.
 */
function strandedTeacher(Workspace $container, string $email): User
{
    $user = User::factory()->create([
        'first_name' => 'خالد',
        'last_name' => 'عبد الباسط',
        'email' => $email,
        'platform_role' => PlatformRole::Teacher,
    ]);

    TeacherApplication::query()->create([
        'workspace_id' => $container->getKey(),
        'user_id' => $user->getKey(),
        'status' => TeacherApplication::STATUS_DRAFT,
        'current_step' => 2,
    ]);

    return $user;
}

beforeEach(function (): void {
    [$this->container] = $this->createWorkspaceWithOwner();
    $this->asGuest();
});

it('gives a stranded teacher a workspace with its roles seeded', function (): void {
    $teacher = strandedTeacher($this->container, 'stranded@example.com');

    runBackfill();

    $workspace = Workspace::query()->where('owner_user_id', $teacher->getKey())->sole();

    /*
    | FR-016's whole point. A migration that INSERTED the row by hand would leave
    | this at zero, permanently — `SeedDefaultRoles` fires from `WorkspaceCreated`
    | and runs once, at creation, so a workspace born without it never gets roles.
    | The production row `slug = platform` is the measured proof: written with
    | `forceFill`, and it carries no roles to this day.
    */
    expect(Role::query()->where('team_id', $workspace->getKey())->count())->toBe(4)
        ->and($workspace->name)->toBe('خالد عبد الباسط')
        ->and($teacher->fresh()?->last_workspace_id)->toBe($workspace->getKey());

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());
    expect($teacher->fresh()?->hasRole(Roles::TENANT_OWNER))->toBeTrue();
});

it('never names a backfilled workspace with an empty string', function (): void {
    strandedTeacher($this->container, 'named@example.com');

    runBackfill();

    /*
    | ⚠️ THE DEFECT THIS GUARDS IS SILENT IN EVERY OTHER WAY. `User::name` is an
    | accessor over `first_name`/`last_name`, so a walk selecting only `id` and
    | `platform_role` yields `''` — the slug still generates, nothing throws,
    | the migration reports success, and FR-025 prints a blank label to precisely
    | the people the migration existed to rescue.
    */
    $created = Workspace::query()->whereNot('id', $this->container->getKey())->sole();

    expect($created->name)->not->toBe('')
        ->and(trim($created->name))->not->toBe('');
});

it('does not touch an invited assistant, who carries the teacher role by design', function (): void {
    /*
    | ⛔ THE CONDITION THE FIRST DESIGN OF THIS MIGRATION WAS MISSING.
    |
    | `RegisterAccount` stamps `platform_role = Teacher` on an invited assistant
    | deliberately — «they are on the teacher's side of every question that reads
    | this column». They own no workspace, so a predicate of «teacher AND owns
    | nothing» matches every assistant on the platform: each would be handed
    | `tenant-owner`'s 68 permissions in a workspace of their own, and
    | `CreateWorkspace` overwrites `last_workspace_id` — evicting them from the
    | workspace of the teacher they actually work for. The door this whole spec
    | closes would be reopened from inside a migration.
    */
    $assistant = $this->addWorkspaceMember($this->container, Roles::TEACHER);
    $assistant->forceFill(['platform_role' => PlatformRole::Teacher])->save();

    $before = Workspace::query()->count();

    runBackfill();

    expect(Workspace::query()->count())->toBe($before)
        ->and(Workspace::query()->where('owner_user_id', $assistant->getKey())->exists())->toBeFalse()
        ->and($assistant->fresh()?->last_workspace_id)->toBe($this->container->getKey());
});

it('does not touch anybody whose platform role is not teacher', function (?PlatformRole $role): void {
    $user = User::factory()->create(['platform_role' => $role]);

    TeacherApplication::query()->create([
        'workspace_id' => $this->container->getKey(),
        'user_id' => $user->getKey(),
        'status' => TeacherApplication::STATUS_DRAFT,
        'current_step' => 2,
    ]);

    $before = Workspace::query()->count();

    runBackfill();

    // FR-018. The application row is there on purpose: the role is the condition
    // that refuses them, and a test whose fixture fails two conditions at once
    // measures whichever one fires first.
    expect(Workspace::query()->count())->toBe($before)
        ->and(Workspace::query()->where('owner_user_id', $user->getKey())->exists())->toBeFalse();
})->with([
    'student' => PlatformRole::Student,
    'parent' => PlatformRole::Parent,
    'no role at all' => null,
]);

it('leaves a teacher who already owns a workspace with exactly one', function (): void {
    [$owned, $owner] = $this->createWorkspaceWithOwner([], ['platform_role' => PlatformRole::Teacher]);

    TeacherApplication::query()->create([
        'workspace_id' => $owned->getKey(),
        'user_id' => $owner->getKey(),
        'status' => TeacherApplication::STATUS_DRAFT,
        'current_step' => 2,
    ]);

    runBackfill();

    expect(Workspace::query()->where('owner_user_id', $owner->getKey())->count())->toBe(1);
});

it('is repeatable — a second run changes nothing', function (): void {
    $teacher = strandedTeacher($this->container, 'twice@example.com');

    runBackfill();

    $workspaces = Workspace::query()->count();
    $roles = Role::query()->count();
    $memberships = DB::table('workspace_members')->count();

    runBackfill();

    /*
    | FR-017, and the condition that delivers it is the same one that selects the
    | candidates: after the first pass this teacher OWNS a workspace, so the
    | second pass cannot see them. No separate guard, no marker column — which is
    | also why a run that dies halfway is safe to repeat.
    */
    expect(Workspace::query()->count())->toBe($workspaces)
        ->and(Role::query()->count())->toBe($roles)
        ->and(DB::table('workspace_members')->count())->toBe($memberships)
        ->and(Workspace::query()->where('owner_user_id', $teacher->getKey())->count())->toBe(1);
});
