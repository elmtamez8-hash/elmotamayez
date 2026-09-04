<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Spec 025 · FR-024 — the orphan workspace is emptied, then deleted, and the
 * deletion refuses to happen while anything still points at it.
 *
 * ⚠️ THE REFUSAL CASES ARE THE POINT OF THIS FILE. A delete migration that does
 * not throw when a row remains is a delete migration that has never been tested:
 * the happy path passes just as well against a version with no check in it at
 * all, because on a clean database there is nothing for the check to find.
 */
function runMove(): void
{
    (require base_path(
        'app/Modules/Tenancy/Database/Migrations/2026_09_04_000200_move_platform_workspace_rows_to_owners.php'
    ))->up();
}

function runDelete(): void
{
    (require base_path(
        'app/Modules/Tenancy/Database/Migrations/2026_09_04_000300_delete_platform_workspace.php'
    ))->up();
}

/**
 * The production row, reproduced: no owner, no roles, participating.
 *
 * ⚠️ `forceFill` and not the factory, because that is how the real one was
 * written — and it is why it has carried zero roles since the day it was made.
 * `SeedDefaultRoles` fires from `WorkspaceCreated`, which a direct write never
 * dispatches. FR-016 exists because of this row.
 */
function orphanWorkspace(): Workspace
{
    $workspace = new Workspace;

    $workspace->forceFill([
        'name' => 'المنصة',
        'slug' => 'platform',
        'type' => 'academy',
        'participates_in_marketplace' => true,
    ])->save();

    return $workspace;
}

beforeEach(fn () => $this->asGuest());

it('deletes an orphan that nothing references any more', function (): void {
    $orphan = orphanWorkspace();

    runDelete();

    expect(Workspace::query()->whereKey($orphan->getKey())->exists())->toBeFalse()
        ->and(Workspace::query()->whereNull('owner_user_id')->exists())->toBeFalse();
});

it('returns quietly when there is no orphan at all', function (): void {
    /*
    | ⚠️ NOT A TRIVIAL CASE — it is the one every other test in this repository
    | depends on. `RefreshDatabase` replays every migration in EVERY Feature test,
    | against a database that has never had an orphan in it. A `firstOrFail` here
    | would redden the whole suite on its first test, and the failure would name
    | this migration while looking like a defect in whatever test ran first.
    */
    $before = Workspace::query()->count();

    runDelete();

    expect(Workspace::query()->count())->toBe($before);
});

it('refuses to delete while a partitioned table still points at it', function (): void {
    $orphan = orphanWorkspace();
    $user = User::factory()->create(['platform_role' => PlatformRole::Teacher]);

    TeacherApplication::query()->create([
        'workspace_id' => $orphan->getKey(),
        'user_id' => $user->getKey(),
        'status' => TeacherApplication::STATUS_DRAFT,
        'current_step' => 2,
    ]);

    expect(fn () => runDelete())
        ->toThrow(RuntimeException::class, 'teacher_applications.workspace_id');

    // And it really did stop — a refusal that deleted anyway would be worse than
    // no check, since the message would read as safety.
    expect(Workspace::query()->whereKey($orphan->getKey())->exists())->toBeTrue();
});

it('refuses on a column that means workspace without saying so', function (): void {
    $orphan = orphanWorkspace();

    /*
    | ⚠️ `users.last_workspace_id` HAS NO FOREIGN KEY — measured:
    | `unsignedBigInteger()->nullable()->index()` and nothing else. So deleting
    | the row it names neither throws nor nulls it; it leaves a dangling
    | reference in complete silence. A sweep that only looked for columns literally
    | called `workspace_id` would never see this one.
    */
    User::factory()->create()->forceFill(['last_workspace_id' => $orphan->getKey()])->save();

    expect(fn () => runDelete())
        ->toThrow(RuntimeException::class, 'users.last_workspace_id');
});

it('moves an application, a profile and its slots to their own owner workspace', function (): void {
    $orphan = orphanWorkspace();

    [$owned, $teacher] = $this->createWorkspaceWithOwner([], ['platform_role' => PlatformRole::Teacher]);

    $application = TeacherApplication::query()->create([
        'workspace_id' => $orphan->getKey(),
        'user_id' => $teacher->getKey(),
        'status' => TeacherApplication::STATUS_SUBMITTED,
        'current_step' => 4,
    ]);

    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $orphan->getKey(),
        'user_id' => $teacher->getKey(),
        'approval_status' => TeacherProfile::STATUS_APPROVED,
        'is_publicly_listed' => true,
    ]);

    $slot = AvailabilitySlot::query()->create([
        'workspace_id' => $orphan->getKey(),
        'teacher_profile_id' => $profile->getKey(),
        'day_of_week' => 0,
        'start_time' => '16:00',
        'end_time' => '18:00',
    ]);

    DB::table('platform_metrics_daily')->insert([
        'date' => now()->toDateString(),
        'metric_key' => 'students.active',
        'workspace_id' => $orphan->getKey(),
        'region_id' => 0,
        'numerator' => 3,
        'denominator' => 0,
        'computed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runMove();

    expect(DB::table('teacher_applications')->where('id', $application->getKey())->value('workspace_id'))->toBe($owned->getKey())
        ->and(DB::table('teacher_profiles')->where('id', $profile->getKey())->value('workspace_id'))->toBe($owned->getKey())
        ->and(DB::table('availability_slots')->where('id', $slot->getKey())->value('workspace_id'))->toBe($owned->getKey());

    /*
    | ⚠️ THE EDGE THAT DROPS A TEACHER OFF THE MARKETPLACE WITH NO ERROR ANYWHERE.
    | `is_publicly_listed` is derived from (approved × workspace participates).
    | The orphan participated; a freshly born workspace does not. Move an approved,
    | listed profile without carrying that across and the teacher simply stops
    | appearing — nothing throws, nothing logs, and only this query sees it.
    */
    expect(DB::table('workspaces')->where('id', $owned->getKey())->value('participates_in_marketplace'))->toBeTruthy();

    // Derived nightly — deleted, never moved: carrying it over doubles a number
    // in the platform report.
    expect(DB::table('platform_metrics_daily')->where('workspace_id', $orphan->getKey())->exists())->toBeFalse();

    // And now, and only now, the deletion is allowed to proceed.
    runDelete();
    expect(Workspace::query()->whereNull('owner_user_id')->exists())->toBeFalse();
});

it('does not opt a teacher into the marketplace who was not listed already', function (): void {
    $orphan = orphanWorkspace();
    [$owned, $teacher] = $this->createWorkspaceWithOwner([], ['platform_role' => PlatformRole::Teacher]);

    TeacherProfile::factory()->create([
        'workspace_id' => $orphan->getKey(),
        'user_id' => $teacher->getKey(),
        'approval_status' => TeacherProfile::STATUS_PENDING,
        'is_publicly_listed' => false,
    ]);

    runMove();

    // 001 · FR-001 is preserved, not repealed: participation stays an opt-in and
    // a data migration is not the place anybody opts in.
    expect(DB::table('workspaces')->where('id', $owned->getKey())->value('participates_in_marketplace'))->toBeFalsy();
});

it('stops rather than guess when a row belongs to somebody who owns no workspace', function (): void {
    $orphan = orphanWorkspace();
    $stranded = User::factory()->create(['platform_role' => PlatformRole::Teacher]);

    TeacherApplication::query()->create([
        'workspace_id' => $orphan->getKey(),
        'user_id' => $stranded->getKey(),
        'status' => TeacherApplication::STATUS_DRAFT,
        'current_step' => 2,
    ]);

    // The backfill should have given this account a workspace. It did not, so the
    // move refuses — the migration after this one deletes the workspace for good,
    // and FR-024 makes proving it empty part of the requirement, not a courtesy.
    expect(fn () => runMove())->toThrow(RuntimeException::class, 'owns no workspace');
});
