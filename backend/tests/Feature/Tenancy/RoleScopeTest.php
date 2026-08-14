<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Models\PlatformStaff;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Policies\RolePolicy;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\PlatformStaffDirectory;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
| The role screen reads `roles` directly, and `/admin` is reachable by every
| teacher — so this is the isolation test the constitution asks for on a
| tenant-owned table that just grew a reader.
|
| ⚠️ TWO HALVES, AND EITHER ALONE IS A HOLE. `TeamRoleScope` keeps another
| workspace's roles out of the LIST; `RolePolicy` keeps them out of the URL. A
| filtered list with an unguarded record is a screen where the id is the
| password.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    [$this->other, $this->otherOwner] = $this->createWorkspaceWithOwner();
});

it('shows a workspace only its own roles', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $teams = Role::query()->get()->pluck('team_id')->unique()->values()->all();

    // Five default roles, all of them this workspace's — and not one row from the
    // workspace next door, which has an identical set under the same names.
    expect($teams)->toBe([$this->workspace->getKey()])
        ->and(Role::query()->count())->toBe(count(Roles::workspaceRoles()));
});

it('refuses the other workspace by id, which is what an address bar asks', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    // Fetched WITHOUT the scope, exactly as route-model binding would if the
    // resource resolved a record before the policy ran.
    $foreign = Role::query()->withoutTeamScope()
        ->where('team_id', $this->other->getKey())
        ->where('name', Roles::TEACHER)
        ->firstOrFail();

    $policy = new RolePolicy;

    expect($policy->view($this->owner, $foreign))->toBeFalse()
        ->and($policy->update($this->owner, $foreign))->toBeFalse()
        ->and($policy->delete($this->owner, $foreign))->toBeFalse();
});

it('lets an owner edit their own role and refuses a teacher the screen entirely', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $mine = Role::query()->where('name', Roles::TEACHER)->firstOrFail();
    $teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);

    $policy = new RolePolicy;

    expect($policy->update($this->owner, $mine))->toBeTrue()
        // `roles.manage` is the owner's alone: a teacher who could edit the
        // teacher role could grant themselves everything the owner holds.
        ->and($policy->viewAny($teacher))->toBeFalse();
});

it('refuses to delete a default role, whose loss nobody could undo', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $default = Role::query()->where('name', Roles::STUDENT)->firstOrFail();

    $invented = Role::query()->create([
        'name' => 'مصحّح',
        'guard_name' => 'web',
        'team_id' => $this->workspace->getKey(),
    ]);

    $policy = new RolePolicy;

    // `SeedDefaultRoles` runs once, at workspace creation. A deleted `student`
    // never comes back, and every member holding it loses everything at once.
    expect($policy->delete($this->owner, $default))->toBeFalse()
        ->and($policy->delete($this->owner, $invented))->toBeTrue();
});

it('keeps the platform roles out of every workspace screen and out of the super admin\'s', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    // Not in the list: they have no team, and the scope asks for this one.
    expect(Role::query()->pluck('name')->all())->not->toContain(Roles::FINANCE_ADMIN);

    $platform = Role::query()->withoutTeamScope()
        ->whereNull('team_id')->where('name', Roles::FINANCE_ADMIN)->firstOrFail();

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $policy = new RolePolicy;

    // And not editable even by the platform: the set behind a platform role is
    // code, reviewed like code. One tick here would mint a second super admin.
    expect($policy->update($superAdmin, $platform))->toBeFalse();
});

it('gives a finance officer their permissions in a workspace they have never joined', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $officer = User::factory()->create();

    PlatformStaff::query()->create([
        'user_id' => $officer->getKey(),
        'role' => Roles::FINANCE_ADMIN,
        'assigned_by' => $this->owner->getKey(),
        'reason' => 'مسؤول التحصيل',
    ]);

    app(PlatformStaffDirectory::class)->forget($officer);

    // The team id is somebody else's workspace, which is the ordinary case for
    // an officer reading receipts: `TeamRoleScope` would hide the teamless role
    // that carries their permissions without the declared bypass in the
    // directory. This is that bypass, asserted.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    expect($officer->can(Permissions::BILLING_PURCHASE_APPROVE))->toBeTrue()
        ->and($officer->can(Permissions::COURSES_DELETE))->toBeFalse();
});
