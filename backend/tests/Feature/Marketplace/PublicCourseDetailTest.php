<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;

/*
| Spec 023 · US1 — the course's own public page.
|
| ⚠️ THE 404 IS THE ASSERTION THAT MATTERS MOST HERE, and it is asserted on the
| BODY rather than on the status. Four different reasons a course may not be
| published must be indistinguishable from outside (FR-007): a status that says
| «not found» while the body says «this teacher is not approved» is an oracle
| answering questions about rows the endpoint refuses to publish. A test that
| checks only `assertNotFound()` passes against exactly that.
|
| ⚠️ AND THE LEAK ASSERTIONS USE AN ASCII SENTINEL, never Arabic.
| `getContent()` escapes non-ASCII, so `not->toContain('مسوّدة')` never matches
| whatever the payload holds — the whole file would pass against a response that
| leaked everything. Precedent: `AssessmentExposureTest::bodyText()`.
*/

const MARKETPLACE_DRAFT_SENTINEL = 'DRAFT-ONLY-LESSON-SENTINEL';

/**
 * A published course with a three-level tree, one draft lesson, and one lesson
 * under an unpublished chapter.
 *
 * @return array{0: Course, 1: Workspace, 2: TeacherProfile}
 */
function publicCourseFixture(bool $participates = true, string $approval = TeacherProfile::STATUS_APPROVED, ?string $slug = null): array
{
    /*
    | ⚠️ THE DEFAULT SLUG IS UNIQUE PER CALL, AND THAT IS THE POINT.
    |
    | It was the literal `calculus-basics` for every course this helper made, and
    | the four-refusals case below builds three of them in three workspaces. That
    | was legal while the index was `unique(workspace_id, slug)`; `/courses/{slug}`
    | made the slug a PLATFORM address, so the same three fixtures now collide on
    | the index — the constraint proving itself on the first run.
    |
    | A caller that asserts on the value passes it explicitly.
    */
    static $sequence = 0;

    $slug ??= 'calculus-basics-'.(++$sequence);

    $workspace = marketplaceWorkspace('Academy', $participates);
    $teacher = marketplaceTeacher($workspace, ['approval_status' => $approval]);

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $teacher, $slug): Course {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'title' => 'أساسيّات التفاضل',
            'slug' => $slug,
            'grade_level' => 'secondary',
            'price_minor' => 24900,
            'currency' => 'QAR',
            'created_by' => $teacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]);

        $section = Section::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'title' => 'المشتقّة', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
            'course_id' => $course->getKey(), 'title' => 'التعريف',
            'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $hiddenChapter = Chapter::create([
            'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
            'course_id' => $course->getKey(), 'title' => 'فصل قيد الإعداد',
            'status' => ContentStatus::Draft, 'order' => 2,
        ]);

        Lesson::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
            'uuid' => Str::uuid(), 'title' => 'تعريف المشتقّة', 'type' => 'video',
            'status' => ContentStatus::Published, 'order' => 1, 'duration_seconds' => 720,
        ]);

        // Draft, under a published chapter.
        Lesson::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
            'uuid' => Str::uuid(), 'title' => MARKETPLACE_DRAFT_SENTINEL, 'type' => 'article',
            'status' => ContentStatus::Draft, 'order' => 2,
        ]);

        // Published, under a DRAFT chapter — the second door, and the one a
        // status check on the lesson alone walks straight through.
        Lesson::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'section_id' => $section->getKey(), 'chapter_id' => $hiddenChapter->getKey(),
            'uuid' => Str::uuid(), 'title' => MARKETPLACE_DRAFT_SENTINEL.'-CHAPTER', 'type' => 'article',
            'status' => ContentStatus::Published, 'order' => 1,
        ]);

        return $course;
    });

    return [$course, $workspace, $teacher];
}

it('opens a published course to an anonymous visitor', function (): void {
    // Named explicitly: this case asserts the value, and the helper's default is
    // a per-call sequence now that the slug is a platform-wide address.
    [$course] = publicCourseFixture(slug: 'calculus-basics');

    $this->asGuest();

    $data = $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->json('data');

    expect($data['uuid'])->toBe($course->uuid)
        ->and($data['title'])->toBe('أساسيّات التفاضل')
        ->and($data['slug'])->toBe('calculus-basics')
        ->and($data['grade_level'])->toBe('secondary')
        ->and($data['teacher']['name'])->not->toBe(' ')
        ->and($data['teacher']['trust_score_band'])->toBeString()
        // The price belongs to the buyable unit's own page (006 · FR-021هـ).
        ->and($data['price_minor'])->toBe(24900);
});

it('publishes the tree three levels deep and counts only what a visitor gets', function (): void {
    [$course] = publicCourseFixture();

    $this->asGuest();

    $response = $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")->assertOk();
    $data = $response->json('data');

    expect($data['curriculum'])->toHaveCount(1)
        ->and($data['curriculum'][0]['title'])->toBe('المشتقّة')
        ->and($data['curriculum'][0]['chapters'])->toHaveCount(1)
        ->and($data['curriculum'][0]['chapters'][0]['title'])->toBe('التعريف')
        ->and($data['curriculum'][0]['chapters'][0]['items'])->toHaveCount(1)
        ->and($data['lessons_count'])->toBe(1);

    // The ASCII sentinel: an Arabic needle would be `\u`-escaped in the raw body
    // and absent whatever the payload holds.
    expect($response->getContent())->not->toContain(MARKETPLACE_DRAFT_SENTINEL);
});

it('publishes no lesson identifier and no media path', function (): void {
    [$course] = publicCourseFixture();

    $this->asGuest();

    $item = $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->json('data.curriculum.0.chapters.0.items.0');

    expect(array_keys($item))->toBe(['title', 'kind', 'duration_seconds']);
});

it('answers every unpublishable course with the identical not-found body', function (): void {
    /*
    | ⚠️ MEASURED WITH DEBUG OFF, WHICH IS THE ONLY SHAPE PRODUCTION SERVES.
    | The test environment runs with `APP_DEBUG` on, so every 404 body carries a
    | stack trace — and a trace differs by the line that raised it, so the four
    | refusals are trivially distinguishable here for a reason that has nothing
    | to do with the endpoint. Asserting on them as-is would fail forever; not
    | asserting on the body at all would pass against a real oracle.
    */
    config(['app.debug' => false]);

    $this->asGuest();

    $bodies = [];

    // 1 · a uuid nobody ever issued
    $bodies['unknown'] = $this->getJson('/api/v1/marketplace/courses/'.Str::uuid())->assertNotFound()->getContent();

    // 2 · a draft course
    [$draft] = publicCourseFixture();
    $draft->forceFill(['status' => 'draft'])->save();
    $bodies['draft'] = $this->getJson("/api/v1/marketplace/courses/{$draft->uuid}")->assertNotFound()->getContent();

    // 3 · an unapproved teacher
    [$pending] = publicCourseFixture(approval: TeacherProfile::STATUS_PENDING);
    $bodies['unapproved'] = $this->getJson("/api/v1/marketplace/courses/{$pending->uuid}")->assertNotFound()->getContent();

    // 4 · a workspace that withdrew from the marketplace
    [$withdrawn] = publicCourseFixture(participates: false);
    $bodies['withdrawn'] = $this->getJson("/api/v1/marketplace/courses/{$withdrawn->uuid}")->assertNotFound()->getContent();

    expect(array_unique(array_values($bodies)))->toHaveCount(1, 'the four refusals must be indistinguishable');
});

/*
| ⚠️ INVERTED, NOT DELETED (2026-09-06).
|
| This case asserted the opposite until today — «refuses a slug in the path,
| because a slug is unique inside one workspace only» — and it was right: the
| index was `unique(workspace_id, slug)`, so two teachers naming a course
| «الرياضيات ٣» produced one address and the public route had no workspace to
| tell them apart. `_000100_make_course_slug_platform_unique` moved the key, the
| same one `/teachers/{slug}` has carried since 2026-08.
|
| Inverting rather than deleting is what keeps the closure readable as a
| DECISION two specs from now, exactly as `AcademySignupUnchangedTest` was
| handled when 025 repealed its clauses.
*/
it('opens a course by its slug, and still opens it by the uuid', function (): void {
    [$course] = publicCourseFixture(slug: 'calculus-basics');

    $this->asGuest();

    $bySlug = $this->getJson('/api/v1/marketplace/courses/calculus-basics')->assertOk()->json('data');
    $byUuid = $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")->assertOk()->json('data');

    // Both addresses, one course. The uuid is not deprecated: every link shared
    // before the slug existed is one, and the page 308s to the canonical form
    // rather than serving two rankings for one page.
    expect($bySlug['uuid'])->toBe($course->uuid)
        ->and($byUuid['slug'])->toBe('calculus-basics');
});

it('still refuses a slug that belongs to a course it may not publish', function (): void {
    // The new key must not become a second door past `publiclyListed()`.
    [$draft] = publicCourseFixture(slug: 'hidden-course');
    $draft->forceFill(['status' => 'draft'])->save();

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses/hidden-course')->assertNotFound();
});
