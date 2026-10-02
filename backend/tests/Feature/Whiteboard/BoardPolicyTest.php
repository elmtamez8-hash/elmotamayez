<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Models\Board;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 039 — who may see, edit, take over and delete a whiteboard (contracts/api.md,
| owner decisions Q5 and D1).
|
| ⚠️ EVERY CASE IS BUILT SO THAT DELETING THE BRANCH IT NAMES TURNS IT RED.
| «A student is refused» passes against a board with no course for the wrong reason
| (the course branch fails anyway), so the cases that test a refusal put the board
| on a LIVE course, where only the guard under test stands in the way.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    // A second teacher, who teaches the course the boards hang off.
    $this->teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->course = Course::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->teacher->getKey(),
    ]);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    // Back to the owner's context after the helpers moved it.
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

function wbBoard(User $creator, ?Course $course = null): Board
{
    return Board::factory()->withPages(1)->create([
        'workspace_id' => $creator->last_workspace_id,
        'owner_user_id' => $creator->getKey(),
        'course_id' => $course?->getKey(),
    ]);
}

function wbAllows(User $user, string $ability, Board $board): bool
{
    return Gate::forUser($user)->inspect($ability, $board)->allowed();
}

function wbConfine(AssistantAssignment $assignment, Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $assignment->getKey(),
        'course_id' => $course->getKey(),
    ]);
    app()->forgetScopedInstances();
}

it('lets the manager who is not the board\'s teacher see, export and delete — and never edit or take over (Q5)', function (): void {
    $board = wbBoard($this->teacher, $this->course);

    expect(wbAllows($this->owner, 'view', $board))->toBeTrue()
        ->and(wbAllows($this->owner, 'export', $board))->toBeTrue()
        ->and(wbAllows($this->owner, 'delete', $board))->toBeTrue()
        ->and(wbAllows($this->owner, 'update', $board))->toBeFalse()
        ->and(wbAllows($this->owner, 'takeLock', $board))->toBeFalse()
        // The course's teacher, for contrast: the same board, every door open.
        ->and(wbAllows($this->teacher, 'update', $board))->toBeTrue()
        ->and(wbAllows($this->teacher, 'takeLock', $board))->toBeTrue();
});

it('lets a manager who teaches the course edit its board — D1, a one-teacher academy', function (): void {
    $ownCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $board = wbBoard($this->assistant, $ownCourse);

    expect(wbAllows($this->owner, 'update', $board))->toBeTrue()
        ->and(wbAllows($this->owner, 'takeLock', $board))->toBeTrue();
});

it('gives a board an assistant prepared to the course\'s teacher, not to the assistant (D1)', function (): void {
    $board = wbBoard($this->assistant, $this->course);

    expect(wbAllows($this->teacher, 'takeLock', $board))->toBeTrue()
        ->and(wbAllows($this->assistant, 'takeLock', $board))->toBeFalse()
        // The assistant still edits it — as one of the course's authors.
        ->and(wbAllows($this->assistant, 'update', $board))->toBeTrue();
});

it('refuses a confined assistant a board whose course is outside the scope, and allows the one inside', function (): void {
    $elsewhere = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    wbConfine($this->assignment, $elsewhere);

    $outside = wbBoard($this->teacher, $this->course);
    $inside = wbBoard($this->teacher, $elsewhere);

    expect(wbAllows($this->assistant, 'update', $outside))->toBeFalse()
        ->and(wbAllows($this->assistant, 'view', $outside))->toBeFalse()
        ->and(wbAllows($this->assistant, 'update', $inside))->toBeTrue();
});

it('refuses a removed member even their own board — a null context is a refusal here', function (): void {
    $board = wbBoard($this->teacher);
    $this->workspace->members()->detach($this->teacher->getKey());
    $this->teacher->forceFill(['last_workspace_id' => null])->save();

    // The removed member's own request: no workspace resolves for them …
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
    $this->actingAs($this->teacher);
    // … while spatie's team id still names the workspace, so a permission check
    // alone would pass — only the policy's own context guard stands in the way.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    expect(app(WorkspaceContext::class)->id())->toBeNull()
        ->and(wbAllows($this->teacher, 'view', $board))->toBeFalse()
        ->and(wbAllows($this->teacher, 'update', $board))->toBeFalse()
        ->and(wbAllows($this->teacher, 'delete', $board))->toBeFalse();
});

/*
| ⚠️ THE CASE ABOVE IS REFUSED BY THE MISSING MEMBERSHIP ROW AS WELL, so on its own
| it passes with the context guard deleted (measured). Here the member is still a
| member and only the null context stands in the way — the shape `BasePolicy`'s
| helper would have allowed.
*/
it('refuses a request that resolves no workspace, even from a member who still is one', function (): void {
    $board = wbBoard($this->teacher, $this->course);
    $this->teacher->forceFill(['last_workspace_id' => null])->save();

    app()->instance(WorkspaceContext::class, new WorkspaceContext);
    $this->actingAs($this->teacher);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    expect(app(WorkspaceContext::class)->id())->toBeNull()
        ->and(wbAllows($this->teacher, 'update', $board))->toBeFalse()
        ->and(wbAllows($this->teacher, 'view', $board))->toBeFalse();
});

it('refuses a student who was handed lessons.manage by mistake, on a board with a live course', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $student->givePermissionTo(Permissions::LESSONS_MANAGE);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $board = wbBoard($this->teacher, $this->course);

    expect($student->can(Permissions::LESSONS_MANAGE))->toBeTrue()
        ->and(wbAllows($student, 'update', $board))->toBeFalse()
        ->and(wbAllows($student, 'view', $board))->toBeFalse()
        ->and(Gate::forUser($student)->inspect('viewAny', Board::class)->allowed())->toBeFalse();
});

it('refuses a guardian account even in a staff role', function (): void {
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    $this->addWorkspaceMember($this->workspace, Roles::TEACHER, $guardian);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $board = wbBoard($this->teacher, $this->course);

    expect($guardian->can(Permissions::LESSONS_MANAGE))->toBeTrue()
        ->and(wbAllows($guardian, 'update', $board))->toBeFalse();
});

it('keeps a board whose course was deleted with its teacher, and away from the course\'s assistants', function (): void {
    $board = wbBoard($this->assistant, $this->course);
    $this->course->delete();
    $board->refresh();

    expect($this->course->fresh()?->trashed() ?? Course::withTrashed()->find($this->course->getKey())->trashed())->toBeTrue()
        ->and(wbAllows($this->assistant, 'update', $board))->toBeFalse()
        ->and(wbAllows($this->teacher, 'update', $board))->toBeTrue()
        ->and(wbAllows($this->teacher, 'takeLock', $board))->toBeTrue();
});

it('answers a board of another workspace as not found', function (): void {
    [$other, $otherOwner] = $this->createWorkspaceWithOwner(['name' => 'Elsewhere']);
    $foreign = app(WorkspaceContext::class)->forWorkspace($other, fn () => Board::factory()->withPages(1)->create([
        'workspace_id' => $other->getKey(),
        'owner_user_id' => $otherOwner->getKey(),
    ]));
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $response = Gate::forUser($this->owner)->inspect('view', $foreign);

    expect($response->allowed())->toBeFalse()
        ->and($response->status())->toBe(404);
});
