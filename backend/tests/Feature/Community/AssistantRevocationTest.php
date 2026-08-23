<?php

declare(strict_types=1);

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-003 · FR-007 · FR-009 — a withdrawal takes effect on the NEXT request.
|
| ⚠️ WITH THE SAME TOKEN, AND THE ASSERTION IS `403` AND NOT `401`. Deleting the
| assistant's Sanctum token would be the easy way to end a session and it is the
| wrong one twice over: an assistant works for more than one teacher, so it signs
| them out of a job they still hold — and a `401` sends them to a login screen
| that will let them straight back in. The withdrawal has to be a refusal, from a
| session that is still perfectly authenticated.
|
| ⚠️ AND THE REQUEST STAYS INSIDE THE SCOPE. Asking about a course the assistant
| was never given would be refused before and after, and the test would prove the
| confinement rather than the withdrawal.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    AssistantScope::factory()->create([
        'assistant_assignment_id' => $this->assignment->getKey(),
        'course_id' => $this->course->getKey(),
    ]);
});

it('refuses the next request after a withdrawal, on the same token and with no second sign-in', function (): void {
    Sanctum::actingAs($this->assistant);

    // The live session: this is work the assistant is doing right now.
    $this->getJson("/api/v1/courses/{$this->course->uuid}/tree")->assertOk();

    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/v1/manage/assistants/{$this->assignment->uuid}")->assertNoContent();

    /*
    | ⚠️ `refresh()`, AND WITHOUT IT THIS TEST ASSERTS THE OPPOSITE OF ITS NAME.
    | `Sanctum::actingAs()` authenticates the in-memory model it is handed rather
    | than re-reading the row, and this one has had its `roles` relation loaded
    | since the fixture assigned it — so the withdrawal deletes the pivot rows
    | while the object in the test still remembers them, and the request passes.
    | A real next request builds the user from the database, which is what
    | `refresh()` reproduces.
    */
    Sanctum::actingAs($this->assistant->refresh());

    // Still signed in — and refused. A 401 here would mean the token was
    // destroyed, which takes the assistant's OTHER teacher away with it.
    $this->getJson("/api/v1/courses/{$this->course->uuid}/tree")->assertForbidden();
});

it('keeps the row and stamps the withdrawal once, however many times it is asked', function (): void {
    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/v1/manage/assistants/{$this->assignment->uuid}")->assertNoContent();

    $stamped = $this->assignment->refresh()->revoked_at;

    expect($stamped)->not->toBeNull();

    /*
    | ⚠️ THE SECOND CALL MUST NOT MOVE THE STAMP. The withdrawal is a conditional
    | UPDATE `WHERE revoked_at IS NULL`, so it is both the check and the claim —
    | the seat idiom. A read-then-write would let a second call re-run the removal
    | side of it, and re-stamp a date that answers «when did this person stop
    | working here» with «whenever somebody last pressed the button».
    */
    $this->deleteJson("/api/v1/manage/assistants/{$this->assignment->uuid}")->assertNoContent();

    expect($this->assignment->refresh()->revoked_at?->toIso8601String())
        ->toBe($stamped?->toIso8601String())
        // Never deleted: `T031` needs this row to keep naming the person who
        // marked a paper months after they left.
        ->and(AssistantAssignment::withoutWorkspaceScope()->whereKey($this->assignment->getKey())->exists())
        ->toBeTrue();
});

it('leaves the assistant everything they hold with the teacher who is staying', function (): void {
    /*
    | spatie runs in TEAM MODE with `team_id = workspace_id`, so a role removal
    | with no team id set deletes every role the person holds anywhere. The same
    | hazard `RevokeWorkspaceAccess` records for a whole workspace, reached here
    | one person at a time — and in production this is the ordinary case, because
    | working for two teachers is what an assistant does.
    */
    [$other, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($other, $otherOwner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($other->getKey());

    $otherCourse = Course::factory()->create(['workspace_id' => $other->getKey()]);
    $this->addWorkspaceMember($other, Roles::ASSISTANT_TEACHER, $this->assistant);

    AssistantAssignment::factory()->create([
        'workspace_id' => $other->getKey(),
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $otherOwner->getKey(),
    ]);

    // ⚠️ BACK TO THE FIRST WORKSPACE BEFORE THE OWNER ACTS. `WorkspaceContext` is
    // a singleton that caches its resolution, and the lines above pointed it at
    // the second workspace — leave it there and the assignment's uuid resolves
    // through a scope that does not contain it, which is a 404 rather than a
    // withdrawal.
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);
    $this->deleteJson("/api/v1/manage/assistants/{$this->assignment->uuid}")->assertNoContent();

    $this->setCurrentWorkspace($other, $this->assistant);
    Sanctum::actingAs($this->assistant->refresh());

    $this->getJson("/api/v1/courses/{$otherCourse->uuid}/tree")->assertOk();
});
