<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Courses\Policies\CoursePolicy;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ «بلا مقابل» AND «متاح بلا تسجيل» ON A LESSON ARE A PRICE (QA 2026-10-29).
|
| `lessons.manage` alone guarded them, and the assistant holds it: an assistant
| ticked `is_free` on a paid lesson and an un-enrolled account then read its
| content at `/learn/lessons/{lesson}` — the course-level hole closed on
| 2026-09-30 (TeacherCoursePricingTest), one level down. Now both flags ask
| `CoursePolicy::changePricing()`, on a CHANGE only.
|
| ⚠️ BOTH DIRECTIONS: «refused» alone is green against a door that refuses
| everybody.
*/

/** @return array{0: Course, 1: Chapter, 2: Lesson} */
function paidLessonFixture(int $workspaceId): array
{
    $course = Course::factory()->published()->create(['workspace_id' => $workspaceId, 'is_free_enrollment' => false]);

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'S', 'status' => ContentStatus::Published,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'C', 'status' => ContentStatus::Published,
    ]);

    $lesson = Lesson::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'L', 'type' => 'article',
        'status' => ContentStatus::Published, 'content' => 'paid',
        'is_free' => false, 'is_preview' => false,
    ]);

    return [$course, $chapter, $lesson];
}

it('refuses an assistant opening a paid lesson, and saves nothing', function (string $flag): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [$course, , $lesson] = paidLessonFixture((int) $workspace->getKey());
    Sanctum::actingAs($this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER));

    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}", [$flag => true])
        ->assertForbidden()
        ->assertJsonPath('message', CoursePolicy::PRICING_REFUSAL);

    expect((bool) $lesson->fresh()?->{$flag})->toBeFalse();
})->with(['is_free', 'is_preview']);

it('refuses an assistant creating a lesson that is born open', function (string $flag): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = paidLessonFixture((int) $workspace->getKey());
    Sanctum::actingAs($this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER));

    $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
        'chapter_uuid' => $chapter->uuid, 'title' => 'مفتوح', 'type' => 'article', $flag => true,
    ])->assertForbidden();

    expect(Lesson::query()->where('title', 'مفتوح')->exists())->toBeFalse();
})->with(['is_free', 'is_preview']);

it('lets an assistant edit and create at the defaults — the flags echoed unchanged', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [$course, $chapter, $lesson] = paidLessonFixture((int) $workspace->getKey());
    Sanctum::actingAs($this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER));

    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}", [
        'title' => 'عنوان جديد', 'is_free' => false, 'is_preview' => false,
    ])->assertOk()->assertJsonPath('can_change_access', false);

    $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
        'chapter_uuid' => $chapter->uuid, 'title' => 'مدفوع', 'type' => 'article', 'is_free' => false,
    ])->assertCreated();
});

it('lets the owner open a lesson, and tells the editor so', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, , $lesson] = paidLessonFixture((int) $workspace->getKey());
    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}", ['is_free' => true])
        ->assertOk()
        ->assertJsonPath('is_free', true)
        ->assertJsonPath('can_change_access', true);
});
