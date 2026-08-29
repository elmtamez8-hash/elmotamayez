<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| FR-046 — the platform's numbers are not a workspace's numbers.
|
| ⚠️ THE CASE THAT MATTERS IS THE ASSISTANT HOLDING `analytics.view`. That
| permission is in the tenant matrix and answers «may you see your own teacher's
| figures»; this screen adds every workspace on the platform together. A door
| built on the wrong one of the two names is not a smaller mistake than no door
| at all — it hands one teacher's assistant every competitor's totals.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
});

it('refuses an assistant who holds the workspace analytics permission', function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $role = Role::findOrCreate('assistant-analytics', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::ANALYTICS_VIEW, 'web'));

    $assistant = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $assistant->assignRole($role);
    $this->setCurrentWorkspace($this->workspace, $assistant);

    Sanctum::actingAs($assistant);

    $this->getJson('/api/v1/reports/platform')->assertForbidden();
    $this->getJson('/api/v1/reports/subscriptions')->assertForbidden();
    $this->putJson('/api/v1/reports/subscriptions', [
        'metric_keys' => ['students.active'],
        'cadence' => 'weekly',
    ])->assertForbidden();
});

it('refuses the workspace owner, who holds every tenant permission there is', function (): void {
    Sanctum::actingAs($this->owner);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->getJson('/api/v1/reports/platform')->assertForbidden();
});

it('refuses an anonymous request before it reaches the permission at all', function (): void {
    $this->getJson('/api/v1/reports/platform')->assertUnauthorized();
});

it('lets a platform officer in', function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $role = Role::findOrCreate('platform-analyst', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::ANALYTICS_CROSS_TEACHER_VIEW, 'web'));

    $analyst = $this->addWorkspaceMember($this->workspace, Roles::TENANT_OWNER);
    $analyst->assignRole($role);
    $this->setCurrentWorkspace($this->workspace, $analyst);

    Sanctum::actingAs($analyst);

    $this->getJson('/api/v1/reports/platform')->assertOk();
});

it('is reachable by the super admin, who holds every platform permission', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

    $this->getJson('/api/v1/reports/platform')->assertOk();
});
