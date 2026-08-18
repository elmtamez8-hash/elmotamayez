<?php

declare(strict_types=1);
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use Laravel\Sanctum\Sanctum;

/*
| ⚠️ THE AUTHOR HAS NO OTHER PLAYER, AND THIS ROUTE REFUSED THEM.
|
| `/learn/lessons/{lesson}` is the only surface in the product that plays a
| lesson, and it required an enrolment — which no teacher holds in their own
| workspace. So the recording button on the teacher's own session page, the one
| link to a published recording, answered «العنصر المطلوب غير موجود أو حُذف»
| about a video they had just taught.
|
| Both directions matter: a workspace member reads it, and a signed-in stranger
| still gets the same 404 as before. A fix that answered for everyone would have
| turned a student route into a public one.
*/
it('serves a lesson to its own workspace member and to nobody outside', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $course = Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    $section = Section::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
    ]);
    $chapter = Chapter::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'section_id' => $section->getKey(),
    ]);
    $lesson = Lesson::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'section_id' => $section->getKey(),
        'chapter_id' => $chapter->getKey(),
        'type' => 'video',
        'status' => 'published',
    ]);

    Sanctum::actingAs($owner);

    $this->getJson("/api/v1/learn/lessons/{$lesson->uuid}")
        ->assertOk()
        ->assertJsonPath('can_access', true)
        // No enrolment behind it — the field a student's screen completes with.
        ->assertJsonPath('enrollment_uuid', null);

    // Someone with an account and no standing in this workspace: unchanged.
    [$otherWorkspace, $stranger] = $this->createWorkspaceWithOwner();
    Sanctum::actingAs($stranger);

    $this->getJson("/api/v1/learn/lessons/{$lesson->uuid}")->assertNotFound();
});
