<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RoleGrants;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| Nobody hands out more than they hold (audit 2026-09-30).
|
| ⚠️ THE ONLY REFUSAL WAS THE OWNER'S OWN ROW. A custom role the owner ticked
| `members.update` on promoted ITSELF to `tenant-owner` — every tenant
| permission, the money included — and `members.invite` invited a new owner.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    /*
    | A coordinator: everything an assistant holds, plus the two member
    | permissions. So `assistant-teacher` and `student` are within their reach,
    | and `teacher` (delete, publish, regenerate, …) and `tenant-owner` are not.
    */
    $coordinator = Role::query()->create([
        'name' => 'منسّق',
        'guard_name' => 'web',
        'team_id' => $this->workspace->getKey(),
    ]);
    $coordinator->syncPermissions([
        ...RolePermissionMatrix::map()[Roles::ASSISTANT_TEACHER],
        Permissions::MEMBERS_UPDATE,
        Permissions::MEMBERS_INVITE,
    ]);

    $this->actor = $this->addWorkspaceMember($this->workspace, 'منسّق');
    $this->other = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
});

function setRoleOf(User $member, string $role): TestResponse
{
    return test()->patchJson('/api/v1/workspaces/'.test()->workspace->uuid.'/members/'.$member->uuid, ['role' => $role]);
}

function inviteAs(string $email, string $role): TestResponse
{
    return test()->postJson('/api/v1/workspaces/'.test()->workspace->uuid.'/invitations', ['email' => $email, 'role' => $role]);
}

it('refuses a delegated member-editor their own row', function (): void {
    Sanctum::actingAs($this->actor);

    setRoleOf($this->actor, Roles::TENANT_OWNER)->assertStatus(422)->assertJsonPath('message', RoleGrants::SELF);
    setRoleOf($this->actor, Roles::STUDENT)->assertStatus(422);
});

it('refuses a delegated member-editor the owner role and any role above their own', function (): void {
    Sanctum::actingAs($this->actor);

    setRoleOf($this->other, Roles::TENANT_OWNER)->assertStatus(422)->assertJsonPath('message', RoleGrants::OWNER_ONLY);
    setRoleOf($this->other, Roles::TEACHER)->assertStatus(422)->assertJsonPath('message', RoleGrants::EXCEEDS);

    // …and lets them move somebody within what they hold.
    setRoleOf($this->other, Roles::STUDENT)->assertNoContent();
});

it('refuses a delegated inviter the owner role and any role above their own', function (): void {
    Sanctum::actingAs($this->actor);

    inviteAs('owner2@example.test', Roles::TENANT_OWNER)->assertStatus(422);
    inviteAs('teacher2@example.test', Roles::TEACHER)->assertStatus(422);
    inviteAs('assistant2@example.test', Roles::ASSISTANT_TEACHER)->assertCreated();
});

it('leaves the owner free to name a second owner, by role change and by invitation', function (): void {
    Sanctum::actingAs($this->owner);

    setRoleOf($this->other, Roles::TENANT_OWNER)->assertNoContent();
    inviteAs('owner3@example.test', Roles::TENANT_OWNER)->assertCreated();
});

it('counts the assistant wall: a walled editor holding a teacher\'s permissions still cannot mint a teacher', function (): void {
    Role::query()->where('name', 'منسّق')->where('team_id', $this->workspace->getKey())->firstOrFail()
        ->givePermissionTo(RolePermissionMatrix::map()[Roles::TEACHER]);

    // Not walled: everything a teacher holds is theirs, so a teacher is theirs to name.
    Sanctum::actingAs($this->actor);
    setRoleOf($this->other, Roles::TEACHER)->assertNoContent();

    // Walled: the same role, but the finances in `teacher` are refused to them.
    AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->actor->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
    app()->forgetScopedInstances();

    $third = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    Sanctum::actingAs($this->actor->fresh());
    setRoleOf($third, Roles::TEACHER)->assertStatus(422)->assertJsonPath('message', RoleGrants::EXCEEDS);
});
