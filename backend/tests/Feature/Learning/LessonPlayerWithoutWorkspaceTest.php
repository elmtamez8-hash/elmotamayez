<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| The only player in the product, opened by the student production actually has.
|
| ⚠️ `EnrollmentPolicy::view()` READS ITS OWNERSHIP BRANCH ONE LINE TOO LATE.
| `belongsToCurrentWorkspace()` fires first, and it compared a real `workspace_id`
| against `WorkspaceContext::id()` — which is NULL for every student, because it
| falls back to `users.last_workspace_id` and nothing on a student's path ever
| writes that column. Enrolling writes nothing; signing in writes nothing. Only
| `CreateWorkspace`, `WorkspaceContext::set()` (from `AcceptInvitation` and
| `SwitchWorkspace`, both about MEMBERS) and the two seeders do.
|
| So `if ($enrollment->student_user_id === $user->getKey()) return allow()` — the
| line that says «this is your own enrolment» — was unreachable for the owner, and
| `/learn/lessons/{uuid}` answered 403 to every student the seeders had not
| stamped. `LessonAccessTest` beside this file passes because its student is built
| with `addWorkspaceMember()`, which attaches the pivot AND stamps the column:
| two things production never does.
|
| ⚠️ THIS FILE MEASURES `BasePolicy` ITSELF, which its LiveSessions sibling cannot:
| `ClassSessionPolicy` grew an ownership branch of its own, so those cases stay
| green even with the base fix reverted. Revert it here and this file fails.
|
| Found on 2026-08-26 by the `T051` walk (spec 017) — the fourth and widest
| instance of one root cause.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $section = Section::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);
    $chapter = Chapter::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'section_id' => $section->getKey(),
    ]);

    $this->lesson = Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'section_id' => $section->getKey(),
        'chapter_id' => $chapter->getKey(),
        'type' => 'video',
        'status' => 'published',
    ]);
});

/** Drop the cached resolution so the next request resolves as the caller would. */
function forgetWorkspaceContext(): void
{
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);
}

it('plays a lesson for a student who belongs to no workspace at all', function (): void {
    $student = User::factory()->create();
    $this->createEnrollment($this->workspace, $this->course, $student);

    // The whole point of the fixture: production never stamps this column, and a
    // test that lets a helper stamp it is testing a person who does not exist.
    expect($student->refresh()->last_workspace_id)->toBeNull();

    Sanctum::actingAs($student);
    forgetWorkspaceContext();

    $this->getJson('/api/v1/learn/lessons/'.$this->lesson->uuid)
        ->assertOk()
        ->assertJsonPath('can_access', true);
});

it('still refuses the same lesson to a signed-in stranger', function (): void {
    // The control. Without it the case above would also pass against a policy
    // that had simply stopped checking anything.
    Sanctum::actingAs(User::factory()->create());
    forgetWorkspaceContext();

    $this->getJson('/api/v1/learn/lessons/'.$this->lesson->uuid)->assertNotFound();
});

it('still refuses a member of another workspace', function (): void {
    // The direction the workspace check exists for, and the one that must not
    // move: a RESOLVED context that does not match is still a denial.
    [, $otherOwner] = $this->createWorkspaceWithOwner();

    Sanctum::actingAs($otherOwner);

    $this->getJson('/api/v1/learn/lessons/'.$this->lesson->uuid)->assertNotFound();
});
