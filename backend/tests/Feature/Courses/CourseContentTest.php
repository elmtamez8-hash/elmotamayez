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
 * The structure endpoints, addressed by uuid.
 *
 * They took serial ids until 016 — `section_id`, `chapter_id` — which the
 * constitution forbids in a payload and which nothing in the product ever
 * noticed, because nothing in the product ever called them.
 */
function publishedSection(int $workspaceId, Course $course, string $title = 'S'): Section
{
    return Section::create([
        'workspace_id' => $workspaceId,
        'course_id' => $course->id,
        'title' => $title,
        'status' => ContentStatus::Published,
    ]);
}

function publishedChapter(int $workspaceId, Course $course, Section $section, string $title = 'C'): Chapter
{
    return Chapter::create([
        'workspace_id' => $workspaceId,
        'section_id' => $section->id,
        'course_id' => $course->id,
        'title' => $title,
        'status' => ContentStatus::Published,
    ]);
}

describe('course sections', function (): void {
    it('lists published sections of a course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        publishedSection($workspace->id, $course, 'S1');

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/courses/{$course->uuid}/sections")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'S1');
    });

    it('keeps a draft section out of the student tree', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'title' => 'Unfinished', 'status' => ContentStatus::Draft,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/courses/{$course->uuid}/sections")->assertOk();

        // The whole body, not just the count: a title that leaks anywhere in the
        // payload is the unfinished lesson leaking.
        expect($response->json())->toHaveCount(0)
            ->and($response->getContent())->not->toContain('Unfinished');
    });

    it('creates a section as a draft', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/courses/{$course->uuid}/sections", ['title' => 'New Section'])
            ->assertCreated()
            ->assertJsonPath('title', 'New Section')
            // A new node is a draft (FR-024): saving into a live course must not
            // change what its students see.
            ->assertJsonPath('status', 'draft');

        expect(Section::where('course_id', $course->id)->count())->toBe(1)
            ->and(Section::first()->workspace_id)->toBe($workspace->id);
    });

    it('exposes no serial id in the section payload', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($owner);

        $body = $this->postJson("/api/v1/courses/{$course->uuid}/sections", ['title' => 'X'])
            ->assertCreated()
            ->json();

        expect($body)->toHaveKey('uuid')
            ->and($body)->not->toHaveKey('id')
            ->and($body)->not->toHaveKey('course_id');
    });

    it('updates a section by uuid', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = publishedSection($workspace->id, $course, 'Old');

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/courses/{$course->uuid}/sections/{$section->uuid}", ['title' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('title', 'Updated');
    });

    it('deletes a section by uuid', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = publishedSection($workspace->id, $course);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/courses/{$course->uuid}/sections/{$section->uuid}")->assertNoContent();

        expect(Section::where('id', $section->id)->exists())->toBeFalse();
    });

    it('denies section management to students', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $this->postJson("/api/v1/courses/{$course->uuid}/sections", ['title' => 'X'])->assertForbidden();
    });
});

describe('course chapters', function (): void {
    it('creates a chapter under a section', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = publishedSection($workspace->id, $course);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/courses/{$course->uuid}/chapters", [
            'section_uuid' => $section->uuid,
            'title' => 'Chapter 1',
        ])->assertCreated()->assertJsonPath('title', 'Chapter 1');

        expect(Chapter::where('course_id', $course->id)->count())->toBe(1);
    });

    it('updates and deletes a chapter by uuid', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = publishedSection($workspace->id, $course);
        $chapter = publishedChapter($workspace->id, $course, $section);

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/courses/{$course->uuid}/chapters/{$chapter->uuid}", ['title' => 'Updated'])
            ->assertOk()->assertJsonPath('title', 'Updated');

        $this->deleteJson("/api/v1/courses/{$course->uuid}/chapters/{$chapter->uuid}")->assertNoContent();
    });
});

describe('course lessons', function (): void {
    it('creates, updates, and deletes a lesson', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = publishedSection($workspace->id, $course);
        $chapter = publishedChapter($workspace->id, $course, $section);

        Sanctum::actingAs($owner);

        $resp = $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
            // One parent, not two. The section is derived from the chapter, so
            // the pair cannot disagree.
            'chapter_uuid' => $chapter->uuid,
            'title' => 'Lesson 1',
            'type' => 'article',
            'content' => 'Body',
        ])->assertCreated()->assertJsonPath('title', 'Lesson 1');

        $lessonUuid = $resp->json('uuid');

        expect(Lesson::where('uuid', $lessonUuid)->first()->section_id)->toBe($section->id);

        $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$lessonUuid}", ['title' => 'Renamed'])
            ->assertOk()->assertJsonPath('title', 'Renamed');

        $this->deleteJson("/api/v1/courses/{$course->uuid}/lessons/{$lessonUuid}")->assertNoContent();

        expect(Lesson::where('course_id', $course->id)->count())->toBe(0);
    });

    it('builds a whole branch using only what the responses return', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($owner);

        // The ordinary three-step, driven the way a client drives it: each call
        // uses an identifier the PREVIOUS response gave it. Reading uuids off
        // the models instead is what let ChapterResource ship with no uuid in it
        // at all — every unit test passed and the second step was impossible.
        $sectionUuid = $this->postJson("/api/v1/courses/{$course->uuid}/sections", ['title' => 'قسم'])
            ->assertCreated()->json('uuid');

        expect($sectionUuid)->toBeString()->not->toBeEmpty();

        $chapterUuid = $this->postJson("/api/v1/courses/{$course->uuid}/chapters", [
            'section_uuid' => $sectionUuid,
            'title' => 'فصل',
        ])->assertCreated()->json('uuid');

        expect($chapterUuid)->toBeString()->not->toBeEmpty();

        $lesson = $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
            'chapter_uuid' => $chapterUuid,
            'title' => 'عنصر',
            'type' => 'article',
            'content' => '**نصّ**',
        ])->assertCreated()->json();

        expect($lesson['chapter_uuid'])->toBe($chapterUuid)
            ->and($lesson['section_uuid'])->toBe($sectionUuid)
            // Rendered per response from the Markdown source, never stored.
            ->and($lesson['content_html'])->toContain('<strong>نصّ</strong>');
    });

    it('strips script from authored text at render', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = publishedSection($workspace->id, $course);
        $chapter = publishedChapter($workspace->id, $course, $section);

        Sanctum::actingAs($owner);

        $lesson = $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
            'chapter_uuid' => $chapter->uuid,
            'title' => 'خبيث',
            'type' => 'article',
            'content' => "مرحباً <script>alert(1)</script>\n\n[اضغط](javascript:alert(2))",
        ])->assertCreated()->json();

        expect($lesson['content_html'])->not->toContain('<script')
            ->not->toContain('javascript:');
    });

    it('refuses a lesson type that is declared but not built', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = publishedSection($workspace->id, $course);
        $chapter = publishedChapter($workspace->id, $course, $section);

        Sanctum::actingAs($owner);

        // Assignments belong to spec 008. The refusal names the reason — an
        // "invalid type" would send the teacher looking for their own mistake.
        $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
            'chapter_uuid' => $chapter->uuid,
            'title' => 'واجب الوحدة',
            'type' => 'assignment',
        ])->assertStatus(422);

        expect(Lesson::where('course_id', $course->id)->count())->toBe(0);
    });
});

describe('cross-course ownership enforcement', function (): void {
    it('prevents updating a section that belongs to a different course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $courseA = Course::factory()->create(['workspace_id' => $workspace->id]);
        $courseB = Course::factory()->create(['workspace_id' => $workspace->id]);
        $sectionOfB = publishedSection($workspace->id, $courseB, 'B Section');

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/courses/{$courseA->uuid}/sections/{$sectionOfB->uuid}", ['title' => 'Hijacked'])
            ->assertNotFound();

        expect(Section::find($sectionOfB->id)->title)->toBe('B Section');
    });

    it('prevents deleting a lesson that belongs to a different course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $courseA = Course::factory()->create(['workspace_id' => $workspace->id]);
        $courseB = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = publishedSection($workspace->id, $courseB);
        $chapter = publishedChapter($workspace->id, $courseB, $section);
        $lesson = Lesson::create([
            'workspace_id' => $workspace->id, 'course_id' => $courseB->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => 'L', 'type' => 'article',
            'status' => ContentStatus::Published, 'content' => 'x',
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/courses/{$courseA->uuid}/lessons/{$lesson->uuid}")->assertNotFound();
        expect(Lesson::where('id', $lesson->id)->exists())->toBeTrue();
    });

    it('rejects creating a chapter with a section from a different course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $courseA = Course::factory()->create(['workspace_id' => $workspace->id]);
        $courseB = Course::factory()->create(['workspace_id' => $workspace->id]);
        $sectionOfB = publishedSection($workspace->id, $courseB, 'B Section');

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/courses/{$courseA->uuid}/chapters", [
            'section_uuid' => $sectionOfB->uuid,
            'title' => 'Bad Chapter',
        ])->assertStatus(422);
    });

    it('rejects a lesson whose chapter belongs to a different course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $courseA = Course::factory()->create(['workspace_id' => $workspace->id]);
        $courseB = Course::factory()->create(['workspace_id' => $workspace->id]);
        $sectionOfB = publishedSection($workspace->id, $courseB);
        $chapterOfB = publishedChapter($workspace->id, $courseB, $sectionOfB);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/courses/{$courseA->uuid}/lessons", [
            'chapter_uuid' => $chapterOfB->uuid,
            'title' => 'Stray',
            'type' => 'article',
        ])->assertStatus(422);
    });
});
