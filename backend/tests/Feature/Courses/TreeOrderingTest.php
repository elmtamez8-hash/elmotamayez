<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Reordering is a write to access rights, not to a display order.
 *
 * `Enrollment::canAccessLesson()` decides what a student may open from the
 * triplet of section, chapter and lesson positions. Every one of those columns
 * defaults to 0 and nothing filled them deliberately — `PublishRecordingAsLesson`
 * wrote `order => 0` for every recording — so sibling order was undefined in any
 * course with two recorded sessions.
 */
function orderingTree(int $workspaceId, int $lessonCount = 3): array
{
    $course = Course::factory()->published()->create([
        'workspace_id' => $workspaceId,
        'is_sequential' => true,
    ]);

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'S', 'status' => ContentStatus::Published,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'C', 'status' => ContentStatus::Published,
    ]);

    $lessons = [];

    for ($i = 1; $i <= $lessonCount; $i++) {
        $lessons[] = Lesson::create([
            'workspace_id' => $workspaceId, 'course_id' => $course->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => "L{$i}", 'type' => 'article',
            'status' => ContentStatus::Published, 'content' => 'x',
        ]);
    }

    return [$course, $chapter, $lessons];
}

it('assigns a distinct position to every new sibling', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [, , $lessons] = orderingTree($workspace->id, 4);

    // Nothing set `order`; the trait appended each one. Distinct and dense.
    expect(collect($lessons)->pluck('order')->all())->toBe([0, 1, 2, 3]);
});

it('keeps positions distinct and dense across repeated reorders', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter, $lessons] = orderingTree($workspace->id, 3);

    Sanctum::actingAs($owner);

    $uuids = collect($lessons)->pluck('uuid')->all();

    foreach ([[2, 0, 1], [1, 2, 0], [0, 2, 1]] as $permutation) {
        $ordered = array_map(fn (int $i): string => $uuids[$i], $permutation);

        $this->putJson("/api/v1/courses/{$course->uuid}/chapters/{$chapter->uuid}/lessons/order", [
            'structure_version' => $course->refresh()->structure_version,
            'order' => $ordered,
        ])->assertOk();

        $stored = Lesson::where('chapter_id', $chapter->id)->orderBy('order')->pluck('uuid')->all();

        expect($stored)->toBe($ordered)
            ->and(Lesson::where('chapter_id', $chapter->id)->pluck('order')->sort()->values()->all())
            ->toBe([0, 1, 2]);
    }
});

it('rejects an order list that is missing a sibling', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter, $lessons] = orderingTree($workspace->id, 3);

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}/chapters/{$chapter->uuid}/lessons/order", [
        'structure_version' => $course->structure_version,
        'order' => [$lessons[0]->uuid, $lessons[1]->uuid],
    ])->assertStatus(422);
});

it('rejects an order list naming something that is not a sibling', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter, $lessons] = orderingTree($workspace->id, 3);

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}/chapters/{$chapter->uuid}/lessons/order", [
        'structure_version' => $course->structure_version,
        'order' => [$lessons[0]->uuid, $lessons[1]->uuid, (string) Str::uuid()],
    ])->assertStatus(422);
});

it('refuses a reorder computed against a stale tree', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter, $lessons] = orderingTree($workspace->id, 3);

    Sanctum::actingAs($owner);

    $staleVersion = $course->structure_version;

    // Somebody else moved something first.
    $this->putJson("/api/v1/courses/{$course->uuid}/chapters/{$chapter->uuid}/lessons/order", [
        'structure_version' => $staleVersion,
        'order' => [$lessons[2]->uuid, $lessons[1]->uuid, $lessons[0]->uuid],
    ])->assertOk();

    // The second editor's map was drawn before that.
    $this->putJson("/api/v1/courses/{$course->uuid}/chapters/{$chapter->uuid}/lessons/order", [
        'structure_version' => $staleVersion,
        'order' => [$lessons[1]->uuid, $lessons[0]->uuid, $lessons[2]->uuid],
    ])->assertStatus(409);

    // And nothing was overwritten.
    expect(Lesson::where('chapter_id', $chapter->id)->orderBy('order')->pluck('uuid')->first())
        ->toBe($lessons[2]->uuid);
});

it('changes what the student may open, not only the stored number', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter, $lessons] = orderingTree($workspace->id, 3);

    $student = $this->addWorkspaceMember($workspace, 'student');
    $enrollment = Enrollment::create([
        'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
    ]);

    // L1 is first, so it opens; L3 is last, so it does not.
    expect($enrollment->canAccessLesson($lessons[0]))->toBeTrue()
        ->and($enrollment->canAccessLesson($lessons[2]))->toBeFalse();

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}/chapters/{$chapter->uuid}/lessons/order", [
        'structure_version' => $course->structure_version,
        'order' => [$lessons[2]->uuid, $lessons[1]->uuid, $lessons[0]->uuid],
    ])->assertOk();

    // The assertion is about ACCESS, not about the order column. A test that
    // read the column would pass even if the gate never consulted it.
    $enrollment->refresh();

    expect($enrollment->canAccessLesson($lessons[2]->refresh()))->toBeTrue()
        ->and($enrollment->canAccessLesson($lessons[0]->refresh()))->toBeFalse();
});
