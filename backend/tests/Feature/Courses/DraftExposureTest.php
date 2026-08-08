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
 * A draft must not reach a student — not its content, not its title, not the
 * fact that it exists.
 *
 * The assertion is deliberately crude: **zero occurrences of the draft's title
 * anywhere in the whole response body**. Checking a named field would only prove
 * the field we thought of is clean, and the leak that matters is the one nobody
 * listed — a title echoed in a count label, a nested relation, an error message.
 *
 * The title is a distinctive sentinel string for the same reason. A leak found
 * by grepping the raw JSON is a leak found however it got there.
 */
const DRAFT_SENTINEL = 'ZANBAQ-DRAFT-SENTINEL';

function exposureTree(int $workspaceId): array
{
    $course = Course::factory()->published()->create(['workspace_id' => $workspaceId]);

    $published = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'قسم منشور', 'status' => ContentStatus::Published, 'order' => 0,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $published->id, 'course_id' => $course->id,
        'title' => 'فصل منشور', 'status' => ContentStatus::Published, 'order' => 0,
    ]);

    Lesson::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'section_id' => $published->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'درس منشور', 'type' => 'article',
        'status' => ContentStatus::Published, 'content' => 'مرئي', 'order' => 0,
    ]);

    // A draft item inside the PUBLISHED chapter — the placement that a naive
    // "filter the sections" implementation walks straight past.
    Lesson::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'section_id' => $published->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => DRAFT_SENTINEL.'-lesson', 'type' => 'article',
        'status' => ContentStatus::Draft, 'content' => DRAFT_SENTINEL.'-body', 'order' => 1,
    ]);

    // And a whole draft branch.
    $draftSection = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => DRAFT_SENTINEL.'-section', 'status' => ContentStatus::Draft, 'order' => 1,
    ]);

    $draftChapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $draftSection->id, 'course_id' => $course->id,
        'title' => DRAFT_SENTINEL.'-chapter', 'status' => ContentStatus::Draft, 'order' => 0,
    ]);

    // Published, but inside a draft chapter inside a draft section. Its own
    // status says "visible"; the chain says otherwise, and the chain wins.
    Lesson::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'section_id' => $draftSection->id, 'chapter_id' => $draftChapter->id,
        'uuid' => Str::uuid(), 'title' => DRAFT_SENTINEL.'-buried', 'type' => 'article',
        'status' => ContentStatus::Published, 'content' => 'x', 'order' => 0,
    ]);

    return [$course, $published];
}

it('leaks no draft to an enrolled student, anywhere in the payload', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');
    [$course] = exposureTree($workspace->id);

    Enrollment::factory()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'student_user_id' => $student->id,
    ]);

    Sanctum::actingAs($student);

    foreach (["/api/v1/courses/{$course->uuid}", "/api/v1/courses/{$course->uuid}/sections"] as $url) {
        $body = $this->getJson($url)->assertOk()->getContent();

        expect($body)->toBeString()
            ->and(substr_count((string) $body, DRAFT_SENTINEL))->toBe(0, "leaked at {$url}");
    }

    // The published item is there — otherwise the test above passes on an empty
    // response and proves nothing.
    // assertJsonFragment, not assertSee: Arabic arrives escaped in the raw body,
    // so a literal string comparison would pass vacuously.
    $this->getJson("/api/v1/courses/{$course->uuid}/sections")
        ->assertJsonFragment(['title' => 'درس منشور']);
});

it('refuses the item endpoint that carries the body, by uuid AND by id', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');
    [$course] = exposureTree($workspace->id);

    Enrollment::factory()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'student_user_id' => $student->id,
    ]);

    Sanctum::actingAs($student);

    // The two endpoints above carry the OUTLINE. This one carries the body — and
    // it never checked the requested item's status: `visibleToStudents` guards the
    // lists, and the three status conditions in `accessTo` apply to the PREVIOUS
    // lesson in the prerequisite query.
    $draft = Lesson::query()->where('title', DRAFT_SENTINEL.'-lesson')->firstOrFail();
    $buried = Lesson::query()->where('title', DRAFT_SENTINEL.'-buried')->firstOrFail();

    foreach ([$draft, $buried] as $hidden) {
        // By uuid, and by autoincrement id — `HasUuid::resolveRouteBindingQuery`
        // resolves either, so no uuid had to be guessed at all: the ids could be
        // walked until one landed in the student's own course.
        foreach ([$hidden->uuid, (string) $hidden->id] as $key) {
            $body = (string) $this->getJson("/api/v1/learn/lessons/{$key}")->getContent();

            expect(substr_count($body, DRAFT_SENTINEL))->toBe(0, "leaked at /learn/lessons/{$key}");
        }

        // And it cannot be completed either — a progress row on an archived item
        // is what made the item undeletable through TreeDeletionGuard.
        $enrollment = Enrollment::query()->where('course_id', $course->id)->firstOrFail();

        $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$hidden->uuid}/complete")
            ->assertStatus(422);
    }

    // The published item still opens — otherwise this passes on a blanket refusal.
    $visible = Lesson::query()->where('title', 'درس منشور')->firstOrFail();

    $this->getJson("/api/v1/learn/lessons/{$visible->uuid}")
        ->assertOk()
        ->assertJsonPath('can_access', true);
});

it('refuses the authoring tree to a student outright', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');
    [$course] = exposureTree($workspace->id);

    Sanctum::actingAs($student);

    // 403, not a filtered tree. The author's endpoint returns drafts by design;
    // the guard is who may call it, not what it hides.
    $this->getJson("/api/v1/courses/{$course->uuid}/tree")->assertForbidden();
});

it('shows the teacher every draft on the authoring tree', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course] = exposureTree($workspace->id);

    Sanctum::actingAs($owner);

    $body = (string) $this->getJson("/api/v1/courses/{$course->uuid}/tree")->assertOk()->getContent();

    // The mirror image of the first test: hiding drafts from their author would
    // be the same bug with the blame reversed.
    expect(substr_count($body, DRAFT_SENTINEL))->toBeGreaterThanOrEqual(4);
});
