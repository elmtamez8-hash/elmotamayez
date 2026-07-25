<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

describe('workspace creation', function (): void {
    it('creates a workspace and seeds default roles', function (): void {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/workspaces', [
            'name' => 'My Academy',
            'type' => 'academy',
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'My Academy')
            ->assertJsonPath('is_owner', true);

        $workspace = Workspace::where('name', 'My Academy')->firstOrFail();

        // Owner should be a member with tenant-owner role.
        expect($workspace->members()->where('user_id', $owner->getKey())->exists())->toBeTrue();

        // Workspace-scoped roles should be seeded.
        foreach (Roles::workspaceRoles() as $roleName) {
            expect(Role::where('name', $roleName)->where('team_id', $workspace->getKey())->exists())->toBeTrue();
        }

        // Owner should have the tenant-owner role within this workspace's team context.
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());
        expect($owner->hasRole(Roles::TENANT_OWNER))->toBeTrue();
    });

    it('validates workspace type', function (): void {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/workspaces', [
            'name' => 'Bad Type',
            'type' => 'invalid',
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);
    });

    it('lists workspaces the user belongs to', function (): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        Sanctum::actingAs($ownerA);

        $this->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonPath('0.name', 'Academy A')
            ->assertJsonMissing(['name' => 'Academy B']);
    });

    it('marks which workspace the request is acting in', function (): void {
        [$workspaceA, $owner] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        $workspaceB = $this->addOwnedWorkspace($owner, 'Academy B');

        $this->setCurrentWorkspace($workspaceB, $owner);
        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/workspaces')->assertOk();

        $current = collect($response->json())->firstWhere('is_current', true);

        expect($current)->not->toBeNull()
            ->and($current['name'])->toBe('Academy B')
            ->and($current['pivot_role'])->toBe(Roles::TENANT_OWNER)
            ->and(collect($response->json())->firstWhere('name', 'Academy A')['is_current'])->toBeFalse();
    });
});

describe('workspace switching', function (): void {
    it('allows switching to a workspace the user belongs to', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/workspaces/{$workspace->uuid}/switch")
            ->assertOk();

        expect($owner->fresh()->last_workspace_id)->toBe($workspace->getKey());
    });
});

describe('workspace settings', function (): void {
    it('allows the owner to update workspace settings', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner(['name' => 'Old Name']);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/workspaces/{$workspace->uuid}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('name', 'New Name');

        expect($workspace->fresh()->name)->toBe('New Name');
    });

    it('denies updating settings to non-owners', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $this->patchJson("/api/v1/workspaces/{$workspace->uuid}", ['name' => 'Hijacked'])
            ->assertForbidden();
    });
});

describe('member removal', function (): void {
    it('allows the owner to remove a member', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $member = $this->addWorkspaceMember($workspace, 'student');

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/workspaces/{$workspace->uuid}/members/{$member->uuid}")
            ->assertNoContent();

        expect($workspace->members()->where('user_id', $member->getKey())->exists())->toBeFalse();
    });

    it('prevents removing the workspace owner', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/workspaces/{$workspace->uuid}/members/{$owner->uuid}")
            ->assertStatus(422);
    });

    it('denies member removal to students', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $studentA = $this->addWorkspaceMember($workspace, 'student');
        $studentB = $this->addWorkspaceMember($workspace, 'student');

        Sanctum::actingAs($studentA);

        $this->deleteJson("/api/v1/workspaces/{$workspace->uuid}/members/{$studentB->uuid}")
            ->assertForbidden();
    });
});

describe('invitation acceptance', function (): void {
    it('allows a user to accept a pending invitation', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $inviteResponse = $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
            'email' => 'invitee@example.com',
            'role' => 'student',
        ])->assertCreated();

        $token = $inviteResponse->json('token');

        $invitee = User::factory()->create(['email' => 'invitee@example.com']);
        Sanctum::actingAs($invitee);

        $this->postJson("/api/v1/workspaces/invitations/{$token}/accept")
            ->assertOk();

        expect($workspace->members()->where('user_id', $invitee->getKey())->exists())->toBeTrue();
    });

    it('rejects an invalid invitation token', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/workspaces/invitations/invalid-token/accept')
            ->assertNotFound();
    });
});
