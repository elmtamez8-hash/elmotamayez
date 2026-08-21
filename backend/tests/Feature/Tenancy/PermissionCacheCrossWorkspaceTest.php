<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Models\Scopes\TeamRoleScope;
use App\Modules\Tenancy\Support\Permissions;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⚠️ THE PERMISSION CACHE IS ONE SHARED OBJECT, AND IT WAS BEING BUILT THROUGH A
 * WORKSPACE-SCOPED RELATION.
 *
 * spatie warms its cache once with `Permission::select()->with('roles')->get()`.
 * Since spec 007 that relation resolved through `Tenancy\Models\Role`, which
 * carries {@see TeamRoleScope} — so the cached map of "which roles hold this
 * permission" contained ONLY the roles of whichever workspace happened to make the
 * first request after a flush. Every other workspace then failed every permission
 * check it had.
 *
 * Measured on the development database before the fix: `sessions.manage` is linked
 * to seven roles and the cache held two. An academy owner opened their panel and
 * found every button refused, because a different owner had opened theirs a second
 * earlier — with nothing logged anywhere.
 *
 * ⚠️ AND EVERY DEPLOY RE-RAN THE RACE: `permission:cache-reset`, `cache:clear`, a
 * TTL lapse, a new Redis. The fix is `Tenancy\Models\Permission`, which overrides
 * that one relation.
 *
 * ⚠️ AND THIS FILE ASSERTS THE MECHANISM, NOT THE SYMPTOM — because the symptom
 * CANNOT BE REPRODUCED HERE, and the first version of this test pretended
 * otherwise.
 *
 * That version warmed the cache under one workspace and asserted the other could
 * still act. It passed with the fix AND WITHOUT IT: the suite runs on
 * `CACHE_STORE=array`, and the cache-writing path the defect needs is the one an
 * HTTP request takes with `EnsureCurrentWorkspace` having pushed the team id — not
 * the one a direct `getPermissions()` call takes. Measured both ways: with and
 * without the fix, the cached role set came back complete.
 *
 * A guard that passes against the bug it names is worse than no guard, so it was
 * replaced by this: the ONE relation the fix overrides, asserted to ignore the
 * team scope. That is the whole of the change, it fails when the change is
 * reverted, and it needs no cache at all.
 *
 * The symptom itself was verified by hand against the development database — see
 * `Tenancy\Models\Permission` for the numbers.
 */
it('resolves every workspace roles for a permission, whatever team is current', function (): void {
    [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    // The current team is A. An ordinary role query sees only A's rows — that is
    // the scope doing its job, asserted below.
    app(PermissionRegistrar::class)->setPermissionsTeamId($workspaceA->getKey());

    $permission = config('permission.models.permission')::query()
        ->where('name', Permissions::SESSIONS_MANAGE)
        ->firstOrFail();

    $teams = $permission->roles()->withoutTeamScope()->pluck('team_id')->unique()->values();

    // Sanity: both workspaces really do hold this permission through a role, so
    // the assertion below is not passing by finding nothing.
    expect($teams)->toContain($workspaceA->getKey())
        ->and($teams)->toContain($workspaceB->getKey());

    /*
    | ⚠️ THE ASSERTION THE FIX EXISTS FOR. `$permission->roles` is the relation
    | spatie walks while building its ONE GLOBAL cache. Scoped to the current team,
    | it caches only that workspace's roles — and every other workspace then fails
    | every permission check it has, until a flush picks a different winner.
    */
    $cached = $permission->roles->pluck('team_id')->unique()->values();

    expect($cached)->toContain($workspaceA->getKey())
        ->and($cached)->toContain($workspaceB->getKey());
});

/*
 * ⚠️ AND THE SCOPE ITSELF MUST STILL BITE.
 *
 * The tempting "fix" is to delete `TeamRoleScope`, which makes the case above pass
 * and hands every teacher a list of every other teacher's roles on `/admin`, with
 * an edit button beside each. The bypass belongs to ONE relation — the
 * cache-building one — and nowhere else.
 */
it('still hides another workspace roles from an ordinary query', function (): void {
    [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspaceA->getKey());

    expect(Role::query()->pluck('team_id')->unique()->all())->toBe([$workspaceA->getKey()])
        ->and(Role::query()->withoutTeamScope()->where('team_id', $workspaceB->getKey())->exists())
        ->toBeTrue();
});
