<?php

declare(strict_types=1);

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| ⛔ `lessons.delete` GUARDED NOTHING UNTIL 2026-09-30.
|
| The three delete doors of the course tree asked `manageLessons()` —
| `lessons.manage` — so the permission the roles screen offers for deleting
| content could be unticked with no effect, and the default assistant (who holds
| `lessons.manage` and NOT `lessons.delete`) removed whole sections. Now
| `CoursePolicy::deleteLessons()`: the workspace, the assistant's scope, then
| the permission that names the act.
|
| ⚠️ BOTH DIRECTIONS: «refused» alone is green against a door that refuses
| everybody.
*/

/** @return array{0: Section, 1: Chapter, 2: Lesson} */
function deletableTree(Course $course): array
{
    $workspaceId = (int) $course->workspace_id;

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'S', 'status' => ContentStatus::Draft,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'C', 'status' => ContentStatus::Draft,
    ]);

    $lesson = Lesson::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'L', 'type' => 'article',
        'status' => ContentStatus::Draft, 'content' => 'x',
    ]);

    return [$section, $chapter, $lesson];
}

/** @return array<string, int> the status each of the three doors answered, lesson first */
function deleteDoors(Course $course, Section $section, Chapter $chapter, Lesson $lesson): array
{
    return [
        'lesson' => test()->deleteJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}")->status(),
        'chapter' => test()->deleteJson("/api/v1/courses/{$course->uuid}/chapters/{$chapter->uuid}")->status(),
        'section' => test()->deleteJson("/api/v1/courses/{$course->uuid}/sections/{$section->uuid}")->status(),
    ];
}

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->otherCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
});

it('refuses the default assistant — lessons.manage, no lessons.delete — at all three doors, and deletes nothing', function (): void {
    [$section, $chapter, $lesson] = deletableTree($this->course);
    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    expect($assistant->can(Permissions::LESSONS_MANAGE))->toBeTrue()
        ->and($assistant->can(Permissions::LESSONS_DELETE))->toBeFalse();

    Sanctum::actingAs($assistant);

    expect(deleteDoors($this->course, $section, $chapter, $lesson))
        ->toBe(['lesson' => 403, 'chapter' => 403, 'section' => 403]);

    expect(Lesson::query()->whereKey($lesson->getKey())->exists())->toBeTrue()
        ->and(Chapter::query()->whereKey($chapter->getKey())->exists())->toBeTrue()
        ->and(Section::query()->whereKey($section->getKey())->exists())->toBeTrue();

    // …and the same assistant still edits the tree: the wall is the DELETE only.
    $this->putJson("/api/v1/courses/{$this->course->uuid}/sections/{$section->uuid}", ['title' => 'جديد'])
        ->assertOk();
});

it('lets the owner and a teacher member delete at all three doors', function (string $who): void {
    $actor = $who === 'owner' ? $this->owner : $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    [$section, $chapter, $lesson] = deletableTree($this->course);

    Sanctum::actingAs($actor);

    expect(deleteDoors($this->course, $section, $chapter, $lesson))
        ->toBe(['lesson' => 204, 'chapter' => 204, 'section' => 204]);
})->with(['owner', 'teacher']);

it('lets an assistant GIVEN lessons.delete delete in their own course and refuses them a far one', function (): void {
    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $assistant->givePermissionTo(Permissions::LESSONS_DELETE);

    $row = AssistantAssignment::factory()->create([
        'assistant_user_id' => $assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $row->getKey(),
        'course_id' => $this->course->getKey(),
    ]);
    app()->forgetScopedInstances();

    [$farSection, $farChapter, $farLesson] = deletableTree($this->otherCourse);
    [$section, $chapter, $lesson] = deletableTree($this->course);

    Sanctum::actingAs($assistant);

    expect(deleteDoors($this->otherCourse, $farSection, $farChapter, $farLesson))
        ->toBe(['lesson' => 403, 'chapter' => 403, 'section' => 403]);

    expect(deleteDoors($this->course, $section, $chapter, $lesson))
        ->toBe(['lesson' => 204, 'chapter' => 204, 'section' => 204]);
});
