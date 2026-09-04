<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

describe('workspace creation', function (): void {
    /*
    | ⛔ INVERTED ON PURPOSE — spec 025 · FR-007, and FR-026 records the decision.
    |
    | This asserted `201` plus four seeded roles for ANY signed-in account. That
    | was measured to be exactly what it says: a student holding zero permissions
    | created three workspaces in a row and became `tenant-owner` — 68
    | permissions, `roles.manage` among them — inside each. The workspace is born
    | with the teacher's account now, so the manual door has no remaining purpose
    | and is closed to everybody but a platform administrator.
    |
    | The seeded-roles half of the old assertion did not disappear: it moved to
    | `ImplicitWorkspaceBirthTest`, where the workspace now actually comes from.
    */
    it('refuses to create a workspace for an ordinary account', function (): void {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $before = Workspace::query()->count();

        $this->postJson('/api/v1/workspaces', [
            'name' => 'My Academy',
            'type' => 'academy',
        ])->assertForbidden();

        // A 403 that wrote a row anyway is worse than a 201.
        expect(Workspace::query()->count())->toBe($before);
    });

    /*
    | ⛔ INVERTED ON PURPOSE — and the ORDER of the two refusals is the content.
    |
    | A teacher who already owns their implicit workspace gets `403`, not the
    | `422` of FR-008: they do not hold `workspaces.create`, so the policy refuses
    | them before the second-workspace rule is ever consulted. FR-008's 422 is
    | reachable only from the `/admin` screen, where the caller DOES hold the
    | permission — which is where it is measured.
    */
    it('refuses a teacher who already owns a workspace, at the door and not at the count', function (): void {
        [, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $before = Workspace::query()->count();

        $this->postJson('/api/v1/workspaces', [
            'name' => 'A Second One',
            'type' => 'teacher',
        ])->assertForbidden();

        expect(Workspace::query()->count())->toBe($before)
            ->and(Workspace::query()->where('owner_user_id', $owner->getKey())->count())->toBe(1);
    });

    /*
    | ⛔ INVERTED ON PURPOSE — spec 025 · FR-007.
    |
    | It asserted `422` on a bad `type`, which required the request to reach
    | validation at all. `authorize()` runs FIRST in a Form Request, so an account
    | without `workspaces.create` is refused before a single rule is evaluated.
    | The validation rules themselves are untouched and still apply to the one
    | caller who gets past the door.
    */
    it('refuses before it validates, because authorize runs first', function (): void {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/workspaces', [
            'name' => 'Bad Type',
            'type' => 'invalid',
        ])->assertForbidden();
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
        /*
        | ⚠️ THE FIXTURE MOVED — spec 025 · FR-008. One owner now holds exactly one
        | workspace, guarded by the Action and by a unique index, so «owns two»
        | can no longer be built. Belonging to two is still perfectly ordinary and
        | is the case this test is actually about: a teacher who owns their own
        | place and assists at somebody else's. The assistants stay, by an explicit
        | product decision — only the word «workspace» goes.
        */
        [$workspaceA, $owner] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
        [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);
        $this->addWorkspaceMember($workspaceB, Roles::TEACHER, $owner);

        $this->setCurrentWorkspace($workspaceB, $owner);
        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/workspaces')->assertOk();

        $current = collect($response->json())->firstWhere('is_current', true);

        expect($current)->not->toBeNull()
            ->and($current['name'])->toBe('Academy B')
            // A member at somebody else's place, not its owner — which is the
            // whole shape spec 025 keeps: the assistants stay, only the word goes.
            ->and($current['pivot_role'])->toBe(Roles::TEACHER)
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

    it('exposes invitation details without authentication', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner(['name' => 'Nour Academy']);
        Sanctum::actingAs($owner);

        $token = $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
            'email' => 'newcomer@example.test',
            'role' => Roles::TEACHER,
        ])->assertCreated()->json('token');

        // The invitee has no account yet: this must work signed out.
        app('auth')->forgetGuards();

        $this->getJson("/api/v1/workspaces/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('workspace_name', 'Nour Academy')
            ->assertJsonPath('email', 'newcomer@example.test')
            ->assertJsonPath('role', Roles::TEACHER)
            ->assertJsonPath('is_expired', false)
            ->assertJsonPath('is_accepted', false);
    });

    it('refuses an invitation accepted from a different account', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        Sanctum::actingAs($owner);

        $token = $this->postJson("/api/v1/workspaces/{$workspace->uuid}/invitations", [
            'email' => 'invited@example.test',
            'role' => Roles::STUDENT,
        ])->assertCreated()->json('token');

        $someoneElse = User::factory()->create(['email' => 'stranger@example.test']);
        Sanctum::actingAs($someoneElse);

        $this->postJson("/api/v1/workspaces/invitations/{$token}/accept")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'invited@example.test'));

        expect($workspace->members()->where('user_id', $someoneElse->getKey())->exists())->toBeFalse();
    });

    it('rejects an invalid invitation token', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/workspaces/invitations/invalid-token/accept')
            ->assertNotFound();
    });
});
