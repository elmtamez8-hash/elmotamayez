<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Media\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Spec 032 · US1 — the embedded lesson on the authoring doors.
 *
 * ⚠️ EVERY CASE HERE HAS A BITE-CHECK, named beside it. A test whose guard can
 * be deleted while it stays green is the US6/013 defect: nine cases passed after
 * the check they were written for was removed entirely, because each assertion
 * was individually true of a different condition that fired first.
 */
function embeddedCourse(int $workspaceId): array
{
    $course = Course::factory()->published()->create(['workspace_id' => $workspaceId]);

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'قسم', 'status' => ContentStatus::Published,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'فصل', 'status' => ContentStatus::Published,
    ]);

    return [$course, $chapter];
}

function embeddedLesson(Course $course, Chapter $chapter, array $attributes = []): Lesson
{
    return Lesson::create(array_merge([
        'workspace_id' => $course->workspace_id,
        'course_id' => $course->id,
        'section_id' => $chapter->section_id,
        'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(),
        'title' => 'الحصّة التعريفيّة',
        'type' => LessonType::Embed->value,
        'status' => ContentStatus::Draft,
        'external_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        'is_preview' => true,
        'order' => 0,
    ], $attributes));
}

/*
|--------------------------------------------------------------------------
| SC-009 — the closed host set, enforced on the SERVER
|--------------------------------------------------------------------------
|
| Measured by a direct request, never through a form on a screen: hiding a field
| is not a guard, and a second API reader carries none of the browser's rules
| (FR-004).
|
| BITE-CHECK: drop the `EmbeddedVideoUrl` rule from StoreLessonRequest — every
| row below must turn green-to-red. If any stays 422 it is being refused by
| `url`/`starts_with` and this case is measuring Laravel, not us.
*/
it('the closed host set is enforced server-side', function (string $url): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    Sanctum::actingAs($owner);

    $response = $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
        'chapter_uuid' => $chapter->uuid,
        'title' => 'حصّة',
        'type' => 'embed',
        'external_url' => $url,
    ]);

    $response->assertStatus(422);

    // The message NAMES what is accepted. «غير صالح» sends the teacher hunting
    // for their own mistake.
    expect((string) json_encode($response->json(), JSON_UNESCAPED_UNICODE))
        ->toContain('يوتيوب');
})->with([
    'http is mixed content, refused here rather than silently by the browser' => 'http://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'a host outside the closed set' => 'https://www.dailymotion.com/video/x8abcde',
    'a scheme that is not a url at all' => 'javascript:alert(1)',
    'a youtube id outside the pinned 11-character alphabet' => 'https://youtu.be/short',
    'text that is not a link' => 'شاهد الفيديو على قناتي',
    'a vimeo id outside the pinned digit range' => 'https://vimeo.com/12',
]);

it('accepts every shape in the closed set and stores OUR url, never the paste', function (string $paste, string $stored): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    Sanctum::actingAs($owner);

    $response = $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
        'chapter_uuid' => $chapter->uuid,
        'title' => 'حصّة',
        'type' => 'embed',
        'external_url' => $paste,
    ])->assertStatus(201);

    // ⚠️ THE POINT IS WHAT IS STORED, NOT WHAT WAS CHECKED (the 018 rule). What
    // the teacher pasted must not sit in the column one line away from a frame.
    expect(Lesson::query()->where('uuid', $response->json('uuid'))->firstOrFail()->external_url)
        ->toBe($stored);
})->with([
    'youtu.be' => ['https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'watch with a timestamp — the t parameter is dropped' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=90', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'live' => ['https://www.youtube.com/live/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'an already-embedded youtube url is normalised to nocookie' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'vimeo' => ['https://vimeo.com/76979871', 'https://player.vimeo.com/video/76979871'],
    'vimeo player' => ['https://player.vimeo.com/video/76979871', 'https://player.vimeo.com/video/76979871'],
]);

/*
|--------------------------------------------------------------------------
| SC-003 — an embedded lesson is never published locked
|--------------------------------------------------------------------------
|
| TWO doors, not three. Publishing, and un-marking a published one.
|
| ⛔ «change a published locked lesson INTO an embed» is deliberately NOT a case
| here: `ChangeLessonType::assertChangeable()` already refuses a type change on
| ANY published item, so it is green before a line is written. The FR-006 case
| below bites THAT guard and expects ITS message.
|
| BITE-CHECK: delete the isOpen() condition from PublishReadiness and re-run —
| the first case must go red. If it stays green it is measuring the missing
| `external_url` instead of the rule.
*/
it('refuses to publish a locked embedded lesson', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    $locked = embeddedLesson($course, $chapter, ['is_preview' => false, 'is_free' => false]);

    Sanctum::actingAs($owner);

    $response = $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->structure_version,
        'items' => [['uuid' => $locked->uuid, 'status' => 'published']],
    ]);

    $response->assertStatus(422);

    // The refusal says WHAT TO DO, by both roads.
    expect($response->json('message'))->toContain($locked->title)
        ->and($response->json('message'))->toContain('مجّانيّاً')
        ->and($response->json('message'))->toContain('فيديو مرفوع');

    expect($locked->refresh()->status)->toBe(ContentStatus::Draft);
});

it('refuses to take the open mark off a published embedded lesson', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    $open = embeddedLesson($course, $chapter, [
        'status' => ContentStatus::Published, 'is_preview' => true, 'is_free' => false,
    ]);

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$open->uuid}", [
        'is_preview' => false,
    ])->assertStatus(422);

    expect($open->refresh()->is_preview)->toBeTrue();
});

it('accepts turning off is_preview while is_free stays on', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    $open = embeddedLesson($course, $chapter, [
        'status' => ContentStatus::Published, 'is_preview' => true, 'is_free' => true,
    ]);

    Sanctum::actingAs($owner);

    // ⚠️ THE CONDITION IS ON THE RESULTING STATE, NOT ON THE FIELD SENT. Reading
    // the submitted field alone refuses a perfectly legitimate edit: the lesson
    // is still open through `is_free`.
    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$open->uuid}", [
        'is_preview' => false,
    ])->assertOk();

    expect($open->refresh()->isOpen())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The back door — the sharpest case in this file
|--------------------------------------------------------------------------
|
| `ChangeLessonType` carries `external_url` verbatim when the target type asks
| for it, and `link` accepts ANY https url up to 2048 characters. So
| «create a link → change it to embed → publish» plants free text in an
| `<iframe src>` on our own public page, having passed every rule written for a
| different type.
|
| BITE-CHECK: revert the `:117` line to the plain carry and re-run — this must
| go red on its own.
*/
it('does not carry a raw link url through a type change into embed', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    $link = embeddedLesson($course, $chapter, [
        'type' => LessonType::Link->value,
        'external_url' => 'https://evil.example.com/looks-like-a-lesson',
        'status' => ContentStatus::Draft,
    ]);

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$link->uuid}/type", [
        'type' => 'embed',
    ])->assertOk();

    $link->refresh();

    expect($link->type)->toBe('embed')
        ->and($link->external_url)->not->toBe('https://evil.example.com/looks-like-a-lesson')
        // Nothing the builder could not build survives — and the loss is
        // ANNOUNCED rather than silent.
        ->and($link->external_url)->toBeNull();
});

it('canonicalises a good link url through a type change into embed', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    $link = embeddedLesson($course, $chapter, [
        'type' => LessonType::Link->value,
        'external_url' => 'https://youtu.be/dQw4w9WgXcQ',
        'status' => ContentStatus::Draft,
    ]);

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$link->uuid}/type", [
        'type' => 'embed',
    ])->assertOk();

    expect($link->refresh()->external_url)
        ->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
});

/*
|--------------------------------------------------------------------------
| FR-006 — and it bites the guard that ALREADY EXISTS
|--------------------------------------------------------------------------
|
| ⛔ Marked deliberately. `ChangeLessonType::assertChangeable()` has refused a
| type change on any published item since 016, so a NEW guard written for FR-006
| would never be reached and its test would pass green over a build with no rule
| in it at all. The expected message is that guard's own.
*/
it('refuses to retype a published lesson at all — the shipped guard, not a new one', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    $paid = embeddedLesson($course, $chapter, [
        'type' => LessonType::Article->value,
        'content' => 'نصّ',
        'external_url' => null,
        'status' => ContentStatus::Published,
        'is_preview' => false,
    ]);

    Sanctum::actingAs($owner);

    $response = $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$paid->uuid}/type", [
        'type' => 'embed',
    ])->assertStatus(422);

    // `ChangeLessonType:157`'s wording, verbatim in spirit — not a new sentence.
    expect($response->json('message'))->toContain('عنصر منشور');

    expect($paid->refresh()->type)->toBe('article');
});

/*
|--------------------------------------------------------------------------
| SC-001 — watched in full, with not one byte of our storage or bandwidth
|--------------------------------------------------------------------------
|
| ⚠️ POSITIVE AND NEGATIVE TOGETHER. A negation alone passes over an
| implementation that does nothing at all, which is exactly the failure this
| feature could ship as.
*/
it('creates no media asset and issues no grant', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');
    [$course, $chapter] = embeddedCourse($workspace->id);

    $lesson = embeddedLesson($course, $chapter, ['status' => ContentStatus::Published]);

    Enrollment::factory()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'student_user_id' => $student->id,
    ]);

    Sanctum::actingAs($student);

    $response = $this->getJson("/api/v1/learn/lessons/{$lesson->uuid}")->assertOk();

    // POSITIVE: the frame url actually reached the student.
    expect($response->json('lesson.external_url'))
        ->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');

    // NEGATIVE: nothing of ours was spent to deliver it.
    expect(MediaAsset::query()->where('owner_type', Lesson::class)->where('owner_id', $lesson->id)->count())
        ->toBe(0)
        ->and(DB::table('playback_grants')->count())->toBe(0);

    unset($owner);
});

it('counts an embedded lesson in the progress denominator and lets the student finish it', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    $embed = embeddedLesson($course, $chapter, ['status' => ContentStatus::Published]);

    // ⛔ An item that enters the denominator and can NEVER be completed caps
    // every enrolled student below 100% for ever. Both halves are asserted.
    expect(Lesson::query()->where('course_id', $course->id)->countableForProgress()->count())->toBe(1);

    $student = $this->addWorkspaceMember($workspace, 'student');
    $enrollment = Enrollment::factory()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'student_user_id' => $student->id,
    ]);

    Sanctum::actingAs($student);

    $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$embed->uuid}/complete")
        ->assertOk();

    unset($owner);
});

/*
|--------------------------------------------------------------------------
| SC-007 — zero calls to the host, from any path
|--------------------------------------------------------------------------
|
| `preventStrayRequests` turns any outbound HTTP into a failure rather than a
| silent success, so this measures the WHOLE save-and-publish path rather than
| one method somebody remembered to check.
*/
it('never calls the host', function (): void {
    Http::preventStrayRequests();

    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    Sanctum::actingAs($owner);

    $created = $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
        'chapter_uuid' => $chapter->uuid,
        'title' => 'حصّة',
        'type' => 'embed',
        'external_url' => 'https://youtu.be/dQw4w9WgXcQ',
        'is_preview' => true,
        'duration_seconds' => 1200,
    ])->assertStatus(201);

    $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->refresh()->structure_version,
        'items' => [['uuid' => $created->json('uuid'), 'status' => 'published']],
    ])->assertOk();

    expect(true)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The break-report stamp, cleared by its two writers (032 · US3)
|--------------------------------------------------------------------------
|
| ⚠️ HERE RATHER THAN BESIDE THE REPORT ENDPOINT, AND THE REASON IS THE TEST
| HARNESS: `BrokenEmbedReportTest` runs `WithoutMiddleware` so a hundred posts
| survive `throttle:public`, and that also disables `auth:sanctum` — a teacher
| cannot edit anything from there. The behaviour belongs to `Courses` anyway:
| both writers of the column live in this module.
|
| BITE-CHECK: swap `wasChanged('external_url')` for a key-presence test and the
| SECOND case goes red — the controller carries the column forward on every
| edit, so a presence test clears the stamp when the teacher fixes a typo and
| the duplicate guard is defeated by a rename.
*/
it('clears the break-report stamp when the url actually changes', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    $lesson = embeddedLesson($course, $chapter, ['status' => ContentStatus::Published]);
    $lesson->forceFill(['link_reported_at' => now()])->save();

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}", [
        'external_url' => 'https://youtu.be/oHg5SJYRHA0',
    ])->assertOk();

    expect($lesson->refresh()->link_reported_at)->toBeNull();
});

it('does NOT clear it when only the title changes', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    $lesson = embeddedLesson($course, $chapter, ['status' => ContentStatus::Published]);
    $lesson->forceFill(['link_reported_at' => now()])->save();

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}", [
        'title' => 'الحصّة التعريفيّة — منقّحة',
    ])->assertOk();

    expect($lesson->refresh()->link_reported_at)->not->toBeNull();
});

it('clears it when the type change takes the url away', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$course, $chapter] = embeddedCourse($workspace->id);

    $lesson = embeddedLesson($course, $chapter, ['status' => ContentStatus::Draft]);
    $lesson->forceFill(['link_reported_at' => now()])->save();

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$lesson->uuid}/type", [
        'type' => 'video',
    ])->assertOk();

    expect($lesson->refresh()->link_reported_at)->toBeNull()
        // The type no longer asks for the column, so the url goes with it.
        ->and($lesson->external_url)->toBeNull();
});
