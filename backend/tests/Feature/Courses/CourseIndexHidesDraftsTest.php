<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ `GET /courses` authorised `viewAny` — which is `COURSES_VIEW`, and the
| student role holds it — and then listed `Course::query()` unfiltered. So a
| student member of a workspace read every DRAFT course their teacher had not
| released yet. Whoever cannot edit a course now sees the published ones only,
| the line `CoursePolicy::view()` already draws for a single course.
|
| ⚠️ Both directions: a filter that hides drafts from the teacher too is the
| management screen emptied, and would pass the student case just as well.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();

    $this->published = Course::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->owner->id,
        'status' => 'published',
        'title' => 'PUBLISHED-COURSE',
    ]);

    $this->draft = Course::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->owner->id,
        'status' => 'draft',
        'title' => 'DRAFT-COURSE',
    ]);
});

it('shows a student member the published courses only', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, 'student');
    Sanctum::actingAs($student);

    $uuids = collect($this->getJson('/api/v1/courses')->assertOk()->json('data'))->pluck('uuid')->all();

    expect($uuids)->toContain($this->published->uuid)
        ->not->toContain($this->draft->uuid);
});

/*
| ⛔ AND THE SINGLE-COURSE DOORS. `CoursePolicy::view()` asked `COURSES_VIEW` for
| an unpublished course, so the same student opened the draft by uuid at
| `GET /courses/{course}` and `/courses/{course}/sections` — the index hiding
| it was a list, not a guard. Now `COURSES_UPDATE` or the author (pivot role).
*/
it('refuses a student member the draft at every single-course door', function (string $door): void {
    $student = $this->addWorkspaceMember($this->workspace, 'student');
    Sanctum::actingAs($student);

    $this->getJson(str_replace('{uuid}', $this->draft->uuid, $door))->assertForbidden();
})->with(['/api/v1/courses/{uuid}', '/api/v1/courses/{uuid}/sections']);

it('opens the draft to the owner and to a teacher member', function (string $role): void {
    $reader = $role === 'owner'
        ? $this->owner
        : $this->addWorkspaceMember($this->workspace, $role);

    $this->setCurrentWorkspace($this->workspace, $reader);
    Sanctum::actingAs($reader);

    $this->getJson("/api/v1/courses/{$this->draft->uuid}")->assertOk();
    $this->getJson("/api/v1/courses/{$this->draft->uuid}/sections")->assertOk();
})->with(['owner', 'teacher', 'assistant-teacher']);

it('leaves a published course open to its enrolled student', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, 'student');
    $this->createEnrollment($this->workspace, $this->published, $student);

    Sanctum::actingAs($student);

    $this->getJson("/api/v1/courses/{$this->published->uuid}")->assertOk();
    $this->getJson("/api/v1/courses/{$this->published->uuid}/sections")->assertOk();
});

it('still shows the owner their drafts', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $uuids = collect($this->getJson('/api/v1/courses')->assertOk()->json('data'))->pluck('uuid')->all();

    expect($uuids)->toContain($this->published->uuid)
        ->toContain($this->draft->uuid);
});
