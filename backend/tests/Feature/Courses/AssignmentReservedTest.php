<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Section;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/**
 * `assignment` is KNOWN and NOT BUILT, and both halves are tested (`FR-046`).
 *
 * Known, because the tree has to be designed once: a type invented later would
 * mean revisiting the denominator, the gate, the type-change matrix and the
 * editor. Not built, because the assignment entity — submission, deadline,
 * grading, "the next session opens when it is handed in" — belongs to spec 008
 * in full.
 *
 * So the type appears in the list and is refused BY NAME. "Invalid type" sends
 * the teacher looking for their own mistake; "assignments arrive with the
 * question bank" is an answer.
 *
 * The last case here is the one that matters in six months: it fails the moment
 * somebody flips `implemented` to true without the entity behind it, which is how
 * a placeholder quietly becomes a broken feature.
 */
function assignmentChapter(): array
{
    [$workspace, $owner] = test()->createWorkspaceWithOwner();

    Sanctum::actingAs($owner);
    test()->setCurrentWorkspace($workspace, $owner);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace): array {
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->id]);

        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'title' => 'قسم', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => 'فصل', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        return [$course, $chapter];
    });
}

it('refuses to create an assignment item, naming what brings it', function (): void {
    [$course, $chapter] = assignmentChapter();

    $response = test()->postJson("/api/v1/courses/{$course->uuid}/lessons", [
        'chapter_uuid' => $chapter->uuid,
        'title' => 'الواجب الأول',
        'type' => 'assignment',
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('بنك الأسئلة');
});

it('refuses to change an existing item into an assignment, with the same sentence', function (): void {
    [$course, $chapter] = assignmentChapter();

    $created = test()->postJson("/api/v1/courses/{$course->uuid}/lessons", [
        'chapter_uuid' => $chapter->uuid,
        'title' => 'مقالة',
        'type' => 'article',
    ])->assertCreated();

    // The second door onto the same decision. Refused in the Action, so both
    // verbs answer the same way rather than one of them being validated.
    $response = test()->putJson(
        "/api/v1/courses/{$course->uuid}/lessons/{$created->json('uuid')}/type",
        ['type' => 'assignment'],
    )->assertStatus(422);

    expect($response->json('message'))->toContain('بنك الأسئلة');
});

it('fails if the assignment type is switched on without spec 008 behind it', function (): void {
    // Deliberately a test about the REGISTRY rather than about a response.
    //
    // Flipping `implemented` to true is a one-word change that makes both cases
    // above pass while an assignment item still has no submission, no deadline
    // and no grading — a type that saves and then does nothing. If 008 is landing
    // and this test is in the way, the right move is to delete it in the same
    // change that adds the entity.
    expect(LessonTypeRegistry::isImplemented(LessonType::Assignment))->toBeFalse();
});
