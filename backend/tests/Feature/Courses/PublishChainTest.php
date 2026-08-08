<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Publishing runs in a chain, and the teacher is told which link is broken.
 *
 * A published lesson inside a draft section is hidden. Showing its own status as
 * "منشور" and leaving it at that sends the teacher to fix the item — which is
 * already correct. "منشور، محجوب بقسمه" names the thing they actually have to
 * change (FR-028).
 */
function chainTree(int $workspaceId): array
{
    $course = Course::factory()->published()->create(['workspace_id' => $workspaceId]);

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'قسم', 'status' => ContentStatus::Draft, 'order' => 0,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'فصل', 'status' => ContentStatus::Draft, 'order' => 0,
    ]);

    $lesson = Lesson::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'درس', 'type' => 'article',
        'status' => ContentStatus::Draft, 'content' => 'نصّ', 'order' => 0,
    ]);

    return [$course, $section, $chapter, $lesson];
}

function chainNode(array $tree, string $lessonUuid): array
{
    foreach ($tree['sections'] as $section) {
        foreach ($section['chapters'] as $chapter) {
            foreach ($chapter['lessons'] as $lesson) {
                if ($lesson['uuid'] === $lessonUuid) {
                    return $lesson;
                }
            }
        }
    }

    return [];
}

it('reports a published item as blocked by its section, not as a draft', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, , , $lesson] = chainTree($workspace->id);

    Sanctum::actingAs($owner);

    // Publish the item alone, leaving both ancestors as drafts.
    $tree = $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->structure_version,
        'items' => [['uuid' => $lesson->uuid, 'status' => 'published']],
    ])->assertOk()->json();

    $node = chainNode($tree, $lesson->uuid);

    expect($node['status'])->toBe('published')
        // The state a teacher can act on: its own status is fine, its ancestor
        // is the problem, and the answer names which ancestor.
        ->and($node['blocked_by'])->toBe('section');

    // And it is genuinely invisible, not merely labelled so.
    expect(Lesson::query()->visibleToStudents()->where('id', $lesson->id)->exists())->toBeFalse();
});

it('clears the block when the chain is published in one batch', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $section, $chapter, $lesson] = chainTree($workspace->id);

    Sanctum::actingAs($owner);

    $tree = $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->structure_version,
        'items' => [
            ['uuid' => $section->uuid, 'status' => 'published'],
            ['uuid' => $chapter->uuid, 'status' => 'published'],
            ['uuid' => $lesson->uuid, 'status' => 'published'],
        ],
    ])->assertOk()->json();

    expect(chainNode($tree, $lesson->uuid)['blocked_by'])->toBeNull()
        ->and(Lesson::query()->visibleToStudents()->where('id', $lesson->id)->exists())->toBeTrue();
});

it('moves the structure version once per batch, not once per node', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $section, $chapter, $lesson] = chainTree($workspace->id);

    $before = (int) $course->structure_version;

    Sanctum::actingAs($owner);

    $tree = $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $before,
        'items' => [
            ['uuid' => $section->uuid, 'status' => 'published'],
            ['uuid' => $chapter->uuid, 'status' => 'published'],
            ['uuid' => $lesson->uuid, 'status' => 'published'],
        ],
    ])->assertOk()->json();

    // A batch is one write. Three increments would mean a client that published
    // three nodes could not compute the token for its next call.
    expect($tree['structure_version'])->toBe($before + 1);
});

it('refuses a node that belongs to another course', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course] = chainTree($workspace->id);
    [, , , $stranger] = chainTree($workspace->id);

    Sanctum::actingAs($owner);

    // Same workspace, same teacher, different course. The route names one
    // course; a uuid from another is not addressable through it (FR-059).
    $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->structure_version,
        'items' => [['uuid' => $stranger->uuid, 'status' => 'published']],
    ])->assertStatus(404);
});
