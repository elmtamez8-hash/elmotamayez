<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

/*
| ⚠️ THESE TWO CASES EXIST BECAUSE A REAL DATABASE DISAGREED WITH THE MEASUREMENT.
|
| Production's orphan held rows in four tables, so the move migration handled
| four. A developer's database held six — `blocked_terms` (the six defaults
| `SeedDefaultBlockedTerms` writes, identical in every workspace) and the
| «غير مصنّف» fallback `concepts` row. The delete migration THREW and named the
| table, which is how they were found at all: the reverse-check earning its keep
| outside a test fixture.
|
| They are deleted, not moved, because they are the same category as
| `platform_metrics_daily` — written by machinery, owned by nobody, reproduced in
| every workspace. Everything else still stops the migration.
*/
it('deletes the seeded rows nobody authored, and moves nothing of them', function (): void {
    $orphan = orphanWorkspace();

    // ⚠️ `uuid` written explicitly: a raw insert boots no model, so `HasUuid`
    // never fires — the rule this repository already paid for on the ledger.
    DB::table('blocked_terms')->insert([
        ['uuid' => (string) Str::uuid(), 'workspace_id' => $orphan->getKey(), 'term' => 'واتساب', 'policy' => 'flag', 'created_at' => now(), 'updated_at' => now()],
        ['uuid' => (string) Str::uuid(), 'workspace_id' => $orphan->getKey(), 'term' => 'تلغرام', 'policy' => 'flag', 'created_at' => now(), 'updated_at' => now()],
    ]);

    runMove();

    expect(DB::table('blocked_terms')->where('workspace_id', $orphan->getKey())->exists())->toBeFalse();

    // And the deletion is now actually possible, which is the whole point.
    runDelete();
    expect(Workspace::query()->whereNull('owner_user_id')->exists())->toBeFalse();
});

it('refuses to delete a fallback concept that something still points at', function (): void {
    $orphan = orphanWorkspace();
    [, $teacher] = $this->createWorkspaceWithOwner([], ['platform_role' => PlatformRole::Teacher]);

    $conceptId = DB::table('concepts')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $orphan->getKey(),
        'subject_id' => null,
        'name' => 'غير مصنّف',
        'created_by' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /*
    | ⚠️ THE REFERENCE CHECK IS THE CONDITION THAT MATTERS. «No subject and no
    | creator» identifies a row as auto-created; it does not make it disposable.
    | A concept questions point at is somebody's teaching material, and the right
    | outcome is that the migration stops rather than quietly taking it away.
    */
    DB::table('concept_stats')->insert([
        'workspace_id' => $orphan->getKey(),
        'concept_id' => $conceptId,
        // 0, never null: NULL never equals NULL, so a nullable column in a unique
        // index does not bite — the sentinel is the whole reason this column is
        // NOT NULL with 0 meaning «the concept overall».
        'lesson_id' => 0,
        'attempts_count' => 1,
        'wrong_count' => 0,
        'wrong_pct' => null,
        'computed_at' => now(),
    ]);

    runMove();

    expect(DB::table('concepts')->where('id', $conceptId)->exists())->toBeTrue();

    /*
    | ⚠️ THE ASSERTION IS THE REFUSAL, NOT WHICH TABLE IT NAMES. The sweep walks
    | the schema listing in its own order, so here it reaches `concept_stats`
    | before `concepts` — both are true, and pinning one makes this test fail the
    | day somebody adds a table earlier in the alphabet. What must hold is that
    | the deletion stopped and the concept is still there.
    */
    expect(fn () => runDelete())->toThrow(RuntimeException::class, 'Refusing to delete the orphan workspace');

    expect($teacher->fresh())->not->toBeNull();
});
