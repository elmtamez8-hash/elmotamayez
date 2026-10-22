<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspacePermission;
use Laravel\Sanctum\Sanctum;

/*
| «ما يستطيعه مساعدوك» (owner decision 2026-10-21). The teacher opens a group on
| their OWN workspace's assistant role — never another workspace's, and closing a
| group takes back its own permissions and nothing else.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    [$this->otherWorkspace, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->otherAssistant = $this->addWorkspaceMember($this->otherWorkspace, Roles::ASSISTANT_TEACHER);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

it('opens grading to this workspace\'s assistants and to nobody else\'s', function (): void {
    Sanctum::actingAs($this->owner);

    $this->putJson('/api/v1/manage/assistant-powers', ['groups' => ['grading']])
        ->assertOk()
        ->assertJsonPath('groups.grading', true)
        ->assertJsonPath('groups.chat', false);

    expect(WorkspacePermission::holds($this->assistant, $this->workspace->getKey(), Permissions::GRADING_PERFORM))->toBeTrue()
        ->and(WorkspacePermission::holds($this->otherAssistant, $this->otherWorkspace->getKey(), Permissions::GRADING_PERFORM))->toBeFalse();
});

it('closes a group without touching the defaults or a hand-ticked permission', function (): void {
    $role = Role::query()->withoutTeamScope()
        ->where('team_id', $this->workspace->getKey())
        ->where('name', Roles::ASSISTANT_TEACHER)
        ->firstOrFail();
    $role->givePermissionTo([Permissions::CHAT_REPLY, Permissions::ANNOUNCEMENTS_MANAGE]);

    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/manage/assistant-powers')->assertOk()->assertJsonPath('groups.chat', true);

    $this->putJson('/api/v1/manage/assistant-powers', ['groups' => []])
        ->assertOk()
        ->assertJsonPath('groups.chat', false);

    $held = $role->refresh()->permissions()->pluck('name')->all();

    expect($held)->not->toContain(Permissions::CHAT_REPLY)
        ->toContain(Permissions::ANNOUNCEMENTS_MANAGE)
        ->toContain(Permissions::LESSONS_MANAGE);
});

it('refuses an assistant and a stranger', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->assistant);
    Sanctum::actingAs($this->assistant);
    $this->putJson('/api/v1/manage/assistant-powers', ['groups' => ['grading']])->assertForbidden();

    $stranger = $this->addWorkspaceMember($this->otherWorkspace, Roles::STUDENT);
    $this->setCurrentWorkspace($this->otherWorkspace, $stranger);
    Sanctum::actingAs($stranger);
    $this->getJson('/api/v1/manage/assistant-powers')->assertForbidden();
});

it('refuses a group it does not know', function (): void {
    Sanctum::actingAs($this->owner);

    $this->putJson('/api/v1/manage/assistant-powers', ['groups' => ['payments']])
        ->assertUnprocessable();
});
