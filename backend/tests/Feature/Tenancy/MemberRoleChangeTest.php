<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ `members.update` NAMED A CAPABILITY THE PRODUCT DID NOT HAVE.
|
| Declared since the module existed, seeded, granted to the owner by the matrix,
| and rendered on the roles screen as «تعديل — الأعضاء» — and read by NOTHING.
| There was no PATCH on a member, no relation manager in the panel, no button.
| An owner who wanted to promote their assistant to teacher had exactly one road:
| remove them and invite them again, losing the membership record and putting a
| fresh invitation in their inbox for a job they already held.
|
| ⚠️ THE INTERESTING ASSERTIONS ARE NOT THE PIVOT. The role lives in TWO places —
| `workspace_members.role`, which the members list prints, and spatie's
| `model_has_roles`, which every `can()` in the product reads. Writing one and not
| the other produces a member the screen calls a teacher and the server treats as
| a student, silently, on the permissions half only. So every case here asserts a
| PERMISSION as well as the row.
*/

/** @return array{0: Workspace, 1: User, 2: User} */
function memberRoleFixture(): array
{
    $test = test();

    [$workspace, $owner] = $test->createWorkspaceWithOwner();
    $assistant = $test->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    $test->setCurrentWorkspace($workspace, $owner);

    return [$workspace, $owner, $assistant];
}

/** What the product actually reads: the spatie role under this workspace's team. */
function holdsTeacherOnlyPermission(User $member, mixed $workspace): bool
{
    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): bool => $member->fresh()->can(Permissions::CMS_PUBLISH),
    );
}

it('promotes an assistant to teacher, in both places at once', function (): void {
    [$workspace, $owner, $assistant] = memberRoleFixture();

    // The starting point is the claim: `cms.publish` is the teacher's and is
    // deliberately withheld from an assistant by the matrix.
    expect(holdsTeacherOnlyPermission($assistant, $workspace))->toBeFalse();

    Sanctum::actingAs($owner);

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}/members/{$assistant->uuid}", [
        'role' => Roles::TEACHER,
    ])->assertNoContent();

    $pivot = DB::table('workspace_members')
        ->where('workspace_id', $workspace->getKey())
        ->where('user_id', $assistant->getKey())
        ->value('role');

    expect($pivot)->toBe(Roles::TEACHER)
        ->and(holdsTeacherOnlyPermission($assistant, $workspace))->toBeTrue();
});

it('demotes a teacher and takes the permission away with the label', function (): void {
    [$workspace, $owner, $member] = memberRoleFixture();

    Sanctum::actingAs($owner);

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}/members/{$member->uuid}", [
        'role' => Roles::TEACHER,
    ])->assertNoContent();

    expect(holdsTeacherOnlyPermission($member, $workspace))->toBeTrue();

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}/members/{$member->uuid}", [
        'role' => Roles::STUDENT,
    ])->assertNoContent();

    // The direction that fails silently if the old spatie rows are left behind:
    // the list would read «طالب» over a person who can still publish.
    expect(holdsTeacherOnlyPermission($member, $workspace))->toBeFalse();
});

it('leaves a role the same person holds in another workspace untouched', function (): void {
    /*
    | ⚠️ THE CROSS-TEAM WIPE, AND IT IS WHY THIS DELETES BY `team_id` RATHER THAN
    | CALLING `syncRoles()`. A sync reaches only the roles visible under the
    | CURRENT team id, so the same person's role at a second teacher is swept away
    | by it and put back by nothing — they lose a workspace they were never edited
    | in, and only their permissions half notices.
    */
    [$first, $owner, $member] = memberRoleFixture();

    [$second, $secondOwner] = $this->createWorkspaceWithOwner();
    $this->addWorkspaceMember($second, Roles::TEACHER, $member);

    expect(holdsTeacherOnlyPermission($member, $second))->toBeTrue();

    $this->setCurrentWorkspace($first, $owner);
    Sanctum::actingAs($owner);

    $this->patchJson("/api/v1/workspaces/{$first->uuid}/members/{$member->uuid}", [
        'role' => Roles::STUDENT,
    ])->assertNoContent();

    expect(holdsTeacherOnlyPermission($member, $first))->toBeFalse()
        ->and(holdsTeacherOnlyPermission($member, $second))->toBeTrue()
        ->and($secondOwner->exists)->toBeTrue();
});

it('requires two-factor of somebody it just promoted', function (): void {
    // The same rule `AcceptInvitation` applies when a person joins as a teacher.
    // Promotion is the same grant through a different door, and a door that
    // skipped it would be the quiet way around the mandate.
    [$workspace, $owner, $assistant] = memberRoleFixture();

    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $this->setCurrentWorkspace($workspace, $owner);

    // ⚠️ THE DEADLINE LIVES ON `user_security_settings`, NEVER ON `users` — the
    // repository's own rule that anything true of one role gets its own table.
    // Asserted on `users` this case passes against a build that applies no
    // mandate at all, because the column is null there for everybody.
    expect($student->fresh()->securitySettings?->two_factor_required_at)->toBeNull();

    Sanctum::actingAs($owner);

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}/members/{$student->uuid}", [
        'role' => Roles::TEACHER,
    ])->assertNoContent();

    expect($student->fresh()->securitySettings?->two_factor_required_at)->not->toBeNull()
        ->and($assistant->exists)->toBeTrue();
});

it('refuses to change the role of the owner', function (): void {
    /*
    | Nothing here writes `workspaces.owner_user_id`, so demoting the owner leaves
    | the person who OWNS the workspace holding a student's permissions inside it
    | — locked out of their own product, with no screen able to put it back
    | because putting it back needs the permission they just lost.
    */
    [$workspace, $owner] = memberRoleFixture();

    Sanctum::actingAs($owner);

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}/members/{$owner->uuid}", [
        'role' => Roles::STUDENT,
    ])->assertStatus(422);

    expect(holdsTeacherOnlyPermission($owner, $workspace))->toBeTrue();
});

it('refuses an account that is not on this team', function (): void {
    [$workspace, $owner] = memberRoleFixture();
    $stranger = User::factory()->create();

    Sanctum::actingAs($owner);

    // A sentence and a 422, never a 500 and never a silent success that writes a
    // spatie role for somebody with no membership behind it.
    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}/members/{$stranger->uuid}", [
        'role' => Roles::TEACHER,
    ])->assertStatus(422);

    expect(holdsTeacherOnlyPermission($stranger, $workspace))->toBeFalse();
});

it('refuses a member who does not hold members.update', function (): void {
    // The assistant holds `members.invite` in no role and this one in none
    // either; the point is that the door is the NEW permission and not the one
    // beside it, so an owner may delegate inviting without delegating promotion.
    [$workspace, , $assistant] = memberRoleFixture();

    $target = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $this->setCurrentWorkspace($workspace, $assistant);

    Sanctum::actingAs($assistant);

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}/members/{$target->uuid}", [
        'role' => Roles::TEACHER,
    ])->assertForbidden();

    expect(holdsTeacherOnlyPermission($target, $workspace))->toBeFalse();
});

it('refuses a role outside the four an invitation may name', function (): void {
    // One question, one vocabulary: a role you could be moved to but never
    // invited into is the two-spellings defect wearing a new hat.
    [$workspace, $owner, $assistant] = memberRoleFixture();

    Sanctum::actingAs($owner);

    $this->patchJson("/api/v1/workspaces/{$workspace->uuid}/members/{$assistant->uuid}", [
        'role' => 'super-admin',
    ])->assertStatus(422);
});
