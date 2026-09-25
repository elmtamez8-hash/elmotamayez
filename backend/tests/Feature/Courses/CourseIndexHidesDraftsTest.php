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

it('still shows the owner their drafts', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $uuids = collect($this->getJson('/api/v1/courses')->assertOk()->json('data'))->pluck('uuid')->all();

    expect($uuids)->toContain($this->published->uuid)
        ->toContain($this->draft->uuid);
});
