<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Actions\UpdateWorkspaceMemberRole;
use App\Modules\Tenancy\Events\WorkspaceMemberAdded;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| The assistant assignment follows the membership (audit 2026-09-30).
|
| ⚠️ ONLY JOINING OPENED ONE. `UpdateWorkspaceMemberRole` moved the pivot and
| spatie's role and nothing else, and `RemoveMember` left the row live: a teacher
| demoted to an assistant walked past the financial wall and the confinement,
| an assistant promoted to teacher stayed walled out of their own settlement,
| and a removed assistant stayed «live». Each case below reads the wall through
| `can()` — the reader every screen uses — in the same process as the change,
| so a memo the listener forgot to bust fails it.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
});

function assignmentOf(Workspace $workspace, User $user): ?AssistantAssignment
{
    return AssistantAssignment::query()
        ->withoutWorkspaceScope()
        ->where('workspace_id', $workspace->getKey())
        ->where('assistant_user_id', $user->getKey())
        ->first();
}

function changeRoleAsOwner(User $member, string $role): void
{
    Sanctum::actingAs(test()->owner);

    test()->patchJson('/api/v1/workspaces/'.test()->workspace->uuid.'/members/'.$member->uuid, ['role' => $role])
        ->assertNoContent();
}

/** Whether the wall lets this member read the workspace's orders, asked as a screen asks. */
function readsOrders(User $member): bool
{
    return app(WorkspaceContext::class)->forWorkspace(
        test()->workspace,
        fn (): bool => $member->fresh()?->can(Permissions::ORDERS_VIEW_ALL) ?? false,
    );
}

it('opens an assignment, and the wall, when a teacher is demoted to an assistant', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    expect(readsOrders($member))->toBeTrue();

    changeRoleAsOwner($member, Roles::ASSISTANT_TEACHER);

    $assignment = assignmentOf($this->workspace, $member);
    expect($assignment)->not->toBeNull()
        ->and($assignment?->revoked_at)->toBeNull()
        ->and((int) $assignment?->invited_by_user_id)->toBe((int) $this->owner->getKey());

    // Handed the order read directly, so the WALL — not the smaller role — is
    // what refuses it, in the same process that changed the role.
    $member->fresh()?->givePermissionTo(Permissions::ORDERS_VIEW_ALL);
    expect(readsOrders($member))->toBeFalse();
});

it('closes the assignment, and lifts the wall, when an assistant is promoted to teacher', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    AssistantAssignment::factory()->create([
        'assistant_user_id' => $member->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
    // Read once, so the memo holds «assistant» going into the change.
    expect(readsOrders($member))->toBeFalse();

    changeRoleAsOwner($member, Roles::TEACHER);

    expect(assignmentOf($this->workspace, $member)?->revoked_at)->not->toBeNull()
        ->and(readsOrders($member))->toBeTrue();
});

/** A revoked assignment of `$member`, once confined to `$courses`. */
function revokedAssignmentConfinedTo(User $member, Course ...$courses): AssistantAssignment
{
    $old = AssistantAssignment::factory()->revoked()->create([
        'assistant_user_id' => $member->getKey(),
        'invited_by_user_id' => test()->owner->getKey(),
    ]);

    foreach ($courses as $course) {
        AssistantScope::factory()->create([
            'assistant_assignment_id' => $old->getKey(),
            'course_id' => $course->getKey(),
        ]);
    }

    return $old;
}

function mayActOn(User $member, Course $course): bool
{
    return app(AssistantScopeDirectory::class)->mayActOnCourse($member, (int) test()->workspace->getKey(), (int) $course->getKey());
}

it('re-opens a revoked assignment CONFINED to its old courses (owner decision 2026-09-30)', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $other = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $old = revokedAssignmentConfinedTo($member, $this->course);

    changeRoleAsOwner($member, Roles::ASSISTANT_TEACHER);

    $revived = assignmentOf($this->workspace, $member);
    expect($revived?->getKey())->toBe($old->getKey())
        ->and($revived?->revoked_at)->toBeNull()
        ->and($revived?->scopes()->pluck('course_id')->map(fn ($id): int => (int) $id)->all())->toBe([(int) $this->course->getKey()])
        ->and(mayActOn($member, $this->course))->toBeTrue()
        ->and(mayActOn($member, $other))->toBeFalse();
});

it('re-opens a re-invited assistant CONFINED to their old courses too', function (): void {
    $member = User::factory()->create();
    $other = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    revokedAssignmentConfinedTo($member, $this->course);

    // The acceptance door: `AcceptInvitation` dispatches this after writing the membership.
    $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER, $member);
    WorkspaceMemberAdded::dispatch($this->workspace, $member, Roles::ASSISTANT_TEACHER);

    expect(assignmentOf($this->workspace, $member)?->revoked_at)->toBeNull()
        ->and(mayActOn($member, $this->course))->toBeTrue()
        ->and(mayActOn($member, $other))->toBeFalse();
});

it('keeps a revived assistant whose old courses were all deleted confined to NOTHING, never to everything', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $gone = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $alive = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    revokedAssignmentConfinedTo($member, $this->course, $gone);

    $this->course->delete();
    $gone->forceDelete();

    changeRoleAsOwner($member, Roles::ASSISTANT_TEACHER);

    expect(app(AssistantScopeDirectory::class)->scopedCourseIdsFor($member, (int) $this->workspace->getKey()))->not->toBeNull()
        ->and(mayActOn($member, $alive))->toBeFalse();
});

it('closes the assignment when an assistant is demoted to student', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    AssistantAssignment::factory()->create([
        'assistant_user_id' => $member->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    changeRoleAsOwner($member, Roles::STUDENT);

    expect(assignmentOf($this->workspace, $member)?->revoked_at)->not->toBeNull();
});

it('closes the assignment when the member is removed, and only in that workspace', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    AssistantAssignment::factory()->create([
        'assistant_user_id' => $member->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    [$other, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->addWorkspaceMember($other, Roles::ASSISTANT_TEACHER, $member);
    app(WorkspaceContext::class)->forWorkspace($other, fn () => AssistantAssignment::factory()->create([
        'assistant_user_id' => $member->getKey(),
        'invited_by_user_id' => $otherOwner->getKey(),
    ]));
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    Sanctum::actingAs($this->owner);
    $this->deleteJson('/api/v1/workspaces/'.$this->workspace->uuid.'/members/'.$member->uuid)->assertNoContent();

    expect(assignmentOf($this->workspace, $member)?->revoked_at)->not->toBeNull()
        ->and(assignmentOf($other, $member)?->revoked_at)->toBeNull();
});

it('leaves a live assignment and its scope alone on a move between two assistant roles', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $member->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $assignment->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    // Through the Action: the request's role list is the four built-ins, and the
    // only other assistant role is one an owner invents.
    $invented = Role::query()->create([
        'name' => 'مصحّح',
        'guard_name' => 'web',
        'team_id' => $this->workspace->getKey(),
    ]);
    app(UpdateWorkspaceMemberRole::class)
        ->handle($this->workspace, $member, (string) $invented->name, $this->owner);

    expect(assignmentOf($this->workspace, $member)?->revoked_at)->toBeNull()
        ->and($assignment->scopes()->count())->toBe(1);
});
