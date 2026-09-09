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
use Laravel\Sanctum\Sanctum;

/*
| Spec 032 · US2 — the free preview lesson, watched by a visitor with NO ACCOUNT.
|
| ⛔ THE PREMISE, MEASURED BEFORE THIS SPEC WAS WRITTEN: `is_preview` did not
| mean «open to everyone». It relaxed two conditions for a student who ALREADY
| HELD AN ENROLMENT ROW, and `Modules/Marketplace/` did not mention it in a
| single line. So the free preview was reachable only by people who had already
| bought the course — i.e. by everyone who no longer needed it.
*/

/**
 * @return array{0: Course, 1: Lesson, 2: Workspace, 3: Section, 4: Chapter}
 */
function previewFixture(
    bool $participates = true,
    string $approval = TeacherProfile::STATUS_APPROVED,
    ContentStatus $courseStatus = ContentStatus::Published,
    ContentStatus $sectionStatus = ContentStatus::Published,
    array $lessonAttributes = [],
): array {
    static $sequence = 0;

    $workspace = marketplaceWorkspace('Academy', $participates);
    $teacher = marketplaceTeacher($workspace, ['approval_status' => $approval]);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use (
        $workspace, $teacher, $courseStatus, $sectionStatus, $lessonAttributes, &$sequence
    ): array {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $teacher->user_id,
            'title' => 'الفيزياء ٣',
            'slug' => 'physics-'.(++$sequence),
            'status' => $courseStatus,
        ]);

        $section = Section::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'title' => 'قسم', 'status' => $sectionStatus, 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
            'course_id' => $course->getKey(), 'title' => 'فصل',
            'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $lesson = Lesson::create(array_merge([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
            'uuid' => Str::uuid(),
            'title' => 'الحصّة التعريفيّة — الحركة في بعد واحد',
            'type' => 'embed',
            'status' => ContentStatus::Published,
            'order' => 1,
            'duration_seconds' => 1200,
            'external_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'is_preview' => true,
        ], $lessonAttributes));

        return [$course, $lesson, $workspace, $section, $chapter];
    });
}

/*
|--------------------------------------------------------------------------
| SC-002
|--------------------------------------------------------------------------
*/

it('a guest reads an open embedded lesson', function (): void {
    [$course, $lesson] = previewFixture();

    $this->asGuest();

    $data = $this->getJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}")
        ->assertOk()
        ->json('data');

    expect($data['uuid'])->toBe($lesson->uuid)
        ->and($data['embed_url'])->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and($data['duration_seconds'])->toBe(1200)
        // The invitation to enrol is built from this (FR-013).
        ->and($data['course']['slug'])->toBe($course->slug);
});

it('takes the course slug as readily as its uuid', function (): void {
    // ⚠️ The public course page lives at `/courses/[slug]`, so a route that
    // accepted the uuid alone would 404 every link that page builds — with the
    // uniform body making the cause invisible from both sides.
    [$course, $lesson] = previewFixture();

    $this->asGuest();

    $this->getJson("/api/v1/marketplace/courses/{$course->slug}/lessons/{$lesson->uuid}")
        ->assertOk();
});

it('answers a signed-in teacher from ANOTHER workspace exactly as it answers the guest', function (): void {
    /*
    | ⛔ THE CASE THAT FAILS IF `withoutWorkspaceScope()` IS DROPPED, AND THE ONLY
    | ONE THAT CAN.
    |
    | «A guest activates no scope» is true — `WorkspaceScope::apply()` adds no
    | condition when the context is null — so the guest case above passes whether
    | the bypass exists or not. The person it breaks is a TEACHER SIGNED IN
    | ELSEWHERE: their context resolves from `users.last_workspace_id`, the scope
    | bites, and they are told a lesson that exists does not.
    |
    | Precedent: `DesignScopeBypassTest`, where the asymmetry is the whole point.
    */
    [$course, $lesson] = previewFixture();

    [$otherWorkspace, $otherOwner] = $this->createWorkspaceWithOwner(['name' => 'Another']);
    $this->setCurrentWorkspace($otherWorkspace, $otherOwner);

    Sanctum::actingAs($otherOwner);

    $this->getJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}")
        ->assertOk()
        ->assertJsonPath('data.uuid', $lesson->uuid);

    /*
    | ⛔ AND THE COURSE PAGE THE LINK CAME FROM — because a 404 is not the only
    | shape this defect takes, and the other one answers 200.
    |
    | `withoutWorkspaceScope()` on the outer query does NOT reach inside a
    | `withCount` subquery or a relation eager load: `lessons`, `enrollments`
    | and `teacher_profiles` are all tenant owned, so this reader was shown a
    | course with no teacher, no items and no students. Nothing errors, nothing
    | 404s, and a test asserting only the status code passes over it.
    */
    $page = $this->getJson("/api/v1/marketplace/courses/{$course->uuid}")
        ->assertOk()
        ->assertJsonPath('data.lessons_count', 1)
        ->assertJsonPath('data.curriculum.0.chapters.0.items.0.title', $lesson->title);

    expect($page->json('data.teacher'))->not->toBeNull();
});

it('omits the duration entirely when the teacher left it empty', function (): void {
    // ⚠️ NOT zero and NOT null. The column defaults to 0 and the teacher writes
    // it by hand, so a zero means «not written» — «٠ دقيقة» is a lie, not a
    // blank, and this is the same rule that forbids «أبلغنا المدرّس».
    [$course, $lesson] = previewFixture(lessonAttributes: ['duration_seconds' => 0]);

    $this->asGuest();

    $data = $this->getJson("/api/v1/marketplace/courses/{$course->uuid}/lessons/{$lesson->uuid}")
        ->assertOk()
        ->json('data');

    expect($data)->not->toHaveKey('duration_seconds');
});

/*
|--------------------------------------------------------------------------
| SC-004 — one refusal, seven roads
|--------------------------------------------------------------------------
|
| ⚠️ THE BODY IS COMPARED, NOT ONLY THE STATUS. A 404 whose body differs by the
| reason is an oracle answering questions about rows this door refuses to
| publish — and a test asserting `assertNotFound()` alone passes against exactly
| that.
|
| ⚠️ MEASURED WITH DEBUG OFF, the only shape production serves: the test
| environment's 404 carries a stack trace that differs by the line that raised
| it, so the comparison would be meaningless otherwise.
*/
it('every refusal is byte-identical', function (): void {
    config(['app.debug' => false]);

    $this->asGuest();

    $bodies = [];

    // 1 — a uuid nobody ever issued.
    [$course] = previewFixture();
    $bodies['unknown uuid'] = $this->getJson(
        "/api/v1/marketplace/courses/{$course->uuid}/lessons/".Str::uuid(),
    )->assertNotFound()->getContent();

    // 2 — a locked lesson.
    [$c2, $l2] = previewFixture(lessonAttributes: ['is_preview' => false, 'is_free' => false]);
    $bodies['locked lesson'] = $this->getJson(
        "/api/v1/marketplace/courses/{$c2->uuid}/lessons/{$l2->uuid}",
    )->assertNotFound()->getContent();

    // 3 — a draft course.
    [$c3, $l3] = previewFixture(courseStatus: ContentStatus::Draft);
    $bodies['draft course'] = $this->getJson(
        "/api/v1/marketplace/courses/{$c3->uuid}/lessons/{$l3->uuid}",
    )->assertNotFound()->getContent();

    // 4 — a teacher who is not publicly listed.
    [$c4, $l4] = previewFixture(approval: TeacherProfile::STATUS_PENDING);
    $bodies['unlisted teacher'] = $this->getJson(
        "/api/v1/marketplace/courses/{$c4->uuid}/lessons/{$l4->uuid}",
    )->assertNotFound()->getContent();

    // 5 — an OPEN lesson of another kind. ⛔ Its uuid is never published, so
    // reaching this needs a guessed one — which is exactly the probe FR-009 is
    // about.
    [$c5, $l5] = previewFixture(lessonAttributes: ['type' => 'video', 'external_url' => null]);
    $bodies['open uploaded video'] = $this->getJson(
        "/api/v1/marketplace/courses/{$c5->uuid}/lessons/{$l5->uuid}",
    )->assertNotFound()->getContent();

    // 6 — PUBLISHED, inside a DRAFT SECTION. ⚠️ THE CASE THAT FAILS IF THE
    // QUERY ASKS THE LESSON'S OWN STATUS INSTEAD OF `visibleToStudents()` — and
    // the one that would otherwise leave a public door working for ever after a
    // teacher withdrew a section.
    [$c6, $l6] = previewFixture(sectionStatus: ContentStatus::Draft);
    $bodies['published lesson in a draft section'] = $this->getJson(
        "/api/v1/marketplace/courses/{$c6->uuid}/lessons/{$l6->uuid}",
    )->assertNotFound()->getContent();

    // 7 — a workspace that withdrew from the marketplace (the deleted-course
    // shape from outside: nothing resolves).
    [$c7, $l7] = previewFixture(participates: false);
    $bodies['withdrawn workspace'] = $this->getJson(
        "/api/v1/marketplace/courses/{$c7->uuid}/lessons/{$l7->uuid}",
    )->assertNotFound()->getContent();

    expect(array_unique(array_values($bodies)))->toHaveCount(
        1,
        'each of the seven refusals must be byte-identical: '.json_encode(array_keys($bodies)),
    );
});

it('refuses a lesson from a DIFFERENT course under the same teacher', function (): void {
    // The course key is not decoration: without the `course_id` condition the
    // uuid alone would open any lesson on the platform.
    [$courseA] = previewFixture();
    [, $lessonB] = previewFixture();

    $this->asGuest();

    $this->getJson("/api/v1/marketplace/courses/{$courseA->uuid}/lessons/{$lessonB->uuid}")
        ->assertNotFound();
});
