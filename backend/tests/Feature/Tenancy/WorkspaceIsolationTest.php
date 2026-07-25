<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

describe('workspace isolation', function (): void {
    it('prevents a user from switching to a workspace they do not belong to', function (): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        // Owner A tries to switch to workspace B.
        Sanctum::actingAs($ownerA);

        $this->postJson("/api/v1/workspaces/{$workspaceB->uuid}/switch")
            ->assertForbidden();
    });

    it('prevents viewing members of another workspace', function (): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner();
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($ownerA);

        $this->getJson("/api/v1/workspaces/{$workspaceB->uuid}/members")
            ->assertForbidden();
    });

    it('prevents inviting members to another workspace', function (): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner();
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($ownerA);

        $this->postJson("/api/v1/workspaces/{$workspaceB->uuid}/invitations", [
            'email' => 'new@example.com',
            'role' => Roles::STUDENT,
        ])->assertForbidden();
    });
});

describe('permission enforcement', function (): void {
    it('allows tenant-owner to invite members', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
            'email' => 'invited@example.com',
            'role' => Roles::STUDENT,
        ])->assertCreated();
    });

    it('denies a student from inviting members', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
            'email' => 'invited@example.com',
            'role' => Roles::STUDENT,
        ])->assertForbidden();
    });

    it('allows a super-admin to access any workspace', function (): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner();
        $superAdmin = User::factory()->superAdmin()->create();

        Sanctum::actingAs($superAdmin);

        $this->getJson("/api/v1/workspaces/{$workspaceA->uuid}/members")
            ->assertOk();
    });
});
