<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Marketplace\Models\AvailabilitySlot;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaCaption;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\BelongsToWorkspace;
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

describe('marketplace models are workspace-scoped', function (): void {
    // A tenant-owned model without BelongsToWorkspace leaks silently: it passes
    // every other test in the suite. These cases are the only thing that catches it.
    it('scopes marketplace models to the current workspace', function (string $model): void {
        [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        $context->forWorkspace($workspaceA, fn () => $model::factory()->count(2)->create());
        $context->forWorkspace($workspaceB, fn () => $model::factory()->count(3)->create());

        expect($context->forWorkspace($workspaceA, fn () => $model::query()->count()))->toBe(2);
        expect($context->forWorkspace($workspaceB, fn () => $model::query()->count()))->toBe(3);
    })->with([
        AvailabilitySlot::class,
        GradeLevel::class,
        Subject::class,
        TeacherProfile::class,
    ]);
});

describe('media models are workspace-scoped', function (): void {
    // Required in the same PR that adds the model (Constitution I). Without it a
    // missing BelongsToWorkspace passes every other test and leaks in production.
    it('scopes media assets to the current workspace', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        $context->forWorkspace($workspaceA, fn () => MediaAsset::factory()->count(2)->create(['owner_id' => 1]));
        $context->forWorkspace($workspaceB, fn () => MediaAsset::factory()->count(3)->create(['owner_id' => 1]));

        expect($context->forWorkspace($workspaceA, fn () => MediaAsset::query()->count()))->toBe(2);
        expect($context->forWorkspace($workspaceB, fn () => MediaAsset::query()->count()))->toBe(3);
    });

    it('scopes captions to the current workspace', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

        $context = app(WorkspaceContext::class);

        $context->forWorkspace($workspaceA, function (): void {
            $asset = MediaAsset::factory()->create(['owner_id' => 1]);
            MediaCaption::factory()->create(['media_asset_id' => $asset->getKey()]);
        });

        expect($context->forWorkspace($workspaceB, fn () => MediaCaption::query()->count()))->toBe(0);
    });

    // Platform-owned, and their absence from the list above is the assertion:
    // a device limit copied per workspace is a fresh allowance for every teacher
    // the student enrols with, which is no limit at all.
    it('keeps devices and sessions off the workspace layer', function (): void {
        expect(in_array(BelongsToWorkspace::class, class_uses_recursive(Device::class), true))->toBeFalse()
            ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(AuthSession::class), true))->toBeFalse()
            ->and(in_array(BelongsToWorkspace::class, class_uses_recursive(StudentProfile::class), true))->toBeFalse();
    });
});
