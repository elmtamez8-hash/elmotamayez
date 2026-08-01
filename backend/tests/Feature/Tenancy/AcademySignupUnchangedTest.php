<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/**
 * FR-011 regression net.
 *
 * Adding platform roles and three marketplace signup paths must leave the
 * original route — register, then create a workspace and own it — intact. It is
 * the only way an academy gets onto the platform, and nothing in this feature
 * touches it deliberately, so a failure here means something touched it by
 * accident.
 */
it('still registers an academy owner with no platform role', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'owner@academy.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    $user = User::where('email', 'owner@academy.test')->sole();

    expect($user->platform_role)->toBeNull()
        ->and($user->workspaces()->count())->toBe(0);
});

it('still creates a workspace and makes the registrant its owner', function (): void {
    $owner = User::factory()->create();

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/workspaces', [
        'name' => 'أكاديمية النور',
        'type' => 'academy',
    ])->assertCreated()->assertJsonPath('is_owner', true);

    $workspace = Workspace::query()->where('name', 'أكاديمية النور')->sole();

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());

    expect($workspace->members()->where('user_id', $owner->getKey())->exists())->toBeTrue()
        ->and($owner->hasRole(Roles::TENANT_OWNER))->toBeTrue()
        // The academy path is not a marketplace path: opting in is a separate,
        // deliberate decision (FR-002), not a side effect of signing up.
        ->and($workspace->participates_in_marketplace)->toBeFalse();
});
