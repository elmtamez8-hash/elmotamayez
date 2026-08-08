<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Courses\Support\LessonTypeRegistry;
use App\Modules\Learning\Models\Enrollment;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * One case per content type.
 *
 * The two that matter most are `note` and `link`. Neither can be completed —
 * nothing is asked of the reader of a notice, and the platform cannot know what
 * a student did on someone else's website. So neither may enter the progress
 * denominator, and neither may gate what follows it (FR-012): a course would
 * otherwise stall forever on an unread notice, and a percentage would never
 * reach 100.
 */
function typedCourse(int $workspaceId): array
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

    return [$course, $chapter];
}

function typedLesson(Course $course, Chapter $chapter, string $type, array $attributes = []): Lesson
{
    return Lesson::create(array_merge([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->id,
        'section_id' => $chapter->section_id,
        'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(),
        'title' => "عنصر {$type}",
        'type' => $type,
        'status' => ContentStatus::Published,
    ], $attributes));
}

it('creates every implemented type through the API as a draft', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = typedCourse($workspace->id);

    Sanctum::actingAs($owner);

    foreach (LessonType::cases() as $type) {
        $response = $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
            'chapter_uuid' => $chapter->uuid,
            'title' => "عنصر {$type->value}",
            'type' => $type->value,
        ]);

        if (! LessonTypeRegistry::isImplemented($type)) {
            // Refused by name, not generically: "assignments arrive with the
            // question bank" is an answer a teacher can act on.
            $response->assertStatus(422);
            expect($response->json('message'))->toContain('بنك الأسئلة');

            continue;
        }

        $response->assertStatus(201);
        expect($response->json('status'))->toBe(ContentStatus::Draft->value)
            ->and($response->json('is_completable'))->toBe(LessonTypeRegistry::isCompletable($type));
    }
});

it('keeps note and link out of the progress denominator', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = typedCourse($workspace->id);

    typedLesson($course, $chapter, 'article', ['content' => 'x', 'order' => 0]);
    typedLesson($course, $chapter, 'note', ['content' => 'تنويه', 'order' => 1]);
    typedLesson($course, $chapter, 'link', ['external_url' => 'https://example.com', 'order' => 2]);

    $countable = Lesson::query()->where('course_id', $course->id)->countableForProgress()->count();

    // Three items in the tree; one of them is work.
    expect($countable)->toBe(1);
});

it('does not let a note block the lesson that follows it', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');
    [$course, $chapter] = typedCourse($workspace->id);

    typedLesson($course, $chapter, 'note', ['content' => 'اقرأ هذا', 'order' => 0]);
    $second = typedLesson($course, $chapter, 'article', ['content' => 'الدرس', 'order' => 1]);

    $enrollment = Enrollment::factory()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'student_user_id' => $student->id,
    ]);

    // Sequential course, nothing completed, and the item before this one is a
    // notice. If notices gated, this would be false and the course would be
    // unfinishable by anyone.
    expect($enrollment->canAccessLesson($second))->toBeTrue();

    unset($owner);
});

it('refuses to publish an item missing the field its type needs', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = typedCourse($workspace->id);

    $empty = typedLesson($course, $chapter, 'article', [
        'status' => ContentStatus::Draft,
        'content' => null,
        'order' => 0,
    ]);

    Sanctum::actingAs($owner);

    $response = $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->structure_version,
        'items' => [['uuid' => $empty->uuid, 'status' => 'published']],
    ]);

    $response->assertStatus(422);
    // Names the item as well as the field: a publish is a batch, so "النصّ
    // مطلوب" alone leaves the teacher hunting through eleven items.
    expect($response->json('message'))->toContain($empty->title)
        ->and($response->json('message'))->toContain('النصّ');

    expect($empty->refresh()->status)->toBe(ContentStatus::Draft);
});

it('publishes a complete item and leaves an empty draft saveable', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = typedCourse($workspace->id);

    Sanctum::actingAs($owner);

    // Saving an empty draft must work — that is what a draft is for. The
    // requirement bites at publish and not a moment earlier.
    $created = $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
        'chapter_uuid' => $chapter->uuid,
        'title' => 'مقالة',
        'type' => 'article',
    ])->assertStatus(201);

    $uuid = $created->json('uuid');

    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$uuid}", [
        'content' => 'النصّ الكامل',
    ])->assertOk();

    $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->refresh()->structure_version,
        'items' => [['uuid' => $uuid, 'status' => 'published']],
    ])->assertOk();

    expect(Lesson::query()->where('uuid', $uuid)->firstOrFail()->status)
        ->toBe(ContentStatus::Published);
});

it('refuses a publish computed against a stale tree', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = typedCourse($workspace->id);

    $lesson = typedLesson($course, $chapter, 'article', [
        'status' => ContentStatus::Draft, 'content' => 'x', 'order' => 0,
    ]);

    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->structure_version + 1,
        'items' => [['uuid' => $lesson->uuid, 'status' => 'published']],
    ])->assertStatus(409);
});

it('reports what a type change discards before it happens', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = typedCourse($workspace->id);

    $article = typedLesson($course, $chapter, 'article', [
        'status' => ContentStatus::Draft, 'content' => 'نصّ طويل', 'order' => 0,
    ]);

    Sanctum::actingAs($owner);

    $preview = $this->getJson("/api/v1/courses/{$course->uuid}/lessons/{$article->uuid}/type/link")
        ->assertOk();

    expect($preview->json('losses'))->toContain('النصّ المكتوب')
        // A link cannot be completed, so the item leaves the denominator too —
        // and that is a bigger change than losing the text.
        ->and($preview->json('losses'))->toContain('احتسابه ضمن نسبة تقدّم الطلاب');

    // Reported, not performed.
    expect($article->refresh()->type)->toBe('article')
        ->and($article->content)->toBe('نصّ طويل');
});

it('refuses to retype a published item', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = typedCourse($workspace->id);

    $article = typedLesson($course, $chapter, 'article', ['content' => 'x', 'order' => 0]);

    Sanctum::actingAs($owner);

    $response = $this->putJson(
        "/api/v1/courses/{$course->uuid}/lessons/{$article->uuid}/type",
        ['type' => 'note'],
    );

    // Its type is in every enrolled student's stored percentage right now.
    $response->assertStatus(422);
    expect($article->refresh()->type)->toBe('article');
});

it('derives the course duration from published completable items', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = typedCourse($workspace->id);

    Sanctum::actingAs($owner);

    typedLesson($course, $chapter, 'article', ['content' => 'a', 'duration_seconds' => 300, 'order' => 0]);
    typedLesson($course, $chapter, 'note', ['content' => 'n', 'duration_seconds' => 900, 'order' => 1]);
    $draft = typedLesson($course, $chapter, 'article', [
        'status' => ContentStatus::Draft, 'content' => 'b', 'duration_seconds' => 120, 'order' => 2,
    ]);

    // Any structural write recomputes it.
    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$draft->uuid}", ['title' => 'مسودّة'])
        ->assertOk();

    // 300 only: the notice is not work, and the draft is not visible.
    expect($course->refresh()->duration_seconds)->toBe(300);

    $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->structure_version,
        'items' => [['uuid' => $draft->uuid, 'status' => 'published']],
    ])->assertOk();

    // Publishing changes the published set, so the total has to move with it.
    expect($course->refresh()->duration_seconds)->toBe(420);
});
