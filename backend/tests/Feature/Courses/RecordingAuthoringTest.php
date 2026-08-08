<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * What the authoring surface may and may not do to a session recording.
 *
 * `class_session_id` is not a display detail. It decides that a lesson is watched
 * by whoever held a SEAT in that session rather than by whoever enrolled in the
 * course (005 `FR-030`), so a surface that could write it could hand any lesson's
 * entitlement to an arbitrary session's attendees — and could take a real
 * recording's away by clearing it. `FR-054` puts it out of reach: the 005
 * listener writes it and nothing else does.
 *
 * It is out of reach today because the column is absent from
 * `UpdateLessonRequest::rules()` and because `ManageLessons::update()` builds its
 * attribute array field by field rather than from the payload. Both are the kind
 * of fact a comment can assert and only a test can hold: adding one line to
 * either would open it, and nothing else in the suite would notice.
 *
 * The other half is `FR-052`, and it is the opposite instruction: renaming and
 * moving a recording must WORK. A guard written as "refuse anything touching this
 * row" would be simpler and wrong — the teacher owns where the recording sits in
 * their course, just not who may watch it.
 */

/** @return array{0: Course,1: Lesson,2: Chapter} */
function recordingInACourse(): array
{
    [$workspace, $owner] = test()->createWorkspaceWithOwner();

    Sanctum::actingAs($owner);
    test()->setCurrentWorkspace($workspace, $owner);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner): array {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
        ]);

        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => 'الفصل', 'status' => ContentStatus::Published, 'order' => 1,
        ]);

        $other = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => 'فصل آخر', 'status' => ContentStatus::Published, 'order' => 2,
        ]);

        $recording = Lesson::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => 'تسجيل حصة الأحد', 'type' => 'video',
            'status' => ContentStatus::Published, 'order' => 1,
            'class_session_id' => 4242,
        ]);

        return [$course, $recording, $other];
    });
}

it('ignores class_session_id sent through the authoring endpoint', function (): void {
    [$course, $recording] = recordingInACourse();

    // A plain item, and a payload that tries to make it a recording of somebody
    // else's session — which would hand its entitlement to that session's
    // attendees and take it from the students enrolled here.
    $plain = Lesson::create([
        'workspace_id' => $course->workspace_id, 'course_id' => $course->id,
        'section_id' => $recording->section_id, 'chapter_id' => $recording->chapter_id,
        'uuid' => Str::uuid(), 'title' => 'مقالة', 'type' => 'article',
        'content' => 'نصّ', 'status' => ContentStatus::Published, 'order' => 2,
    ]);

    test()->putJson("/api/v1/courses/{$course->uuid}/lessons/{$plain->uuid}", [
        'title' => 'مقالة',
        'class_session_id' => 4242,
    ])->assertOk();

    expect($plain->refresh()->class_session_id)->toBeNull();
});

it('refuses to let authoring clear the session a recording belongs to', function (): void {
    [$course, $recording] = recordingInACourse();

    test()->putJson("/api/v1/courses/{$course->uuid}/lessons/{$recording->uuid}", [
        'title' => 'تسجيل حصة الأحد',
        'class_session_id' => null,
    ])->assertOk();

    // Cleared, this row stops being a recording: it leaves the seat rule and
    // enters the progress denominator of every enrolled student — including the
    // ones who were never in the room and can never open it.
    expect((int) $recording->refresh()->class_session_id)->toBe(4242);
});

it('accepts renaming and moving a recording, and keeps it a recording', function (): void {
    [$course, $recording, $otherChapter] = recordingInACourse();

    // FR-052: the teacher owns where this sits in their course. Refusing the edit
    // outright would be the easy guard and the wrong one.
    test()->putJson("/api/v1/courses/{$course->uuid}/lessons/{$recording->uuid}", [
        'title' => 'مراجعة الوحدة الأولى',
        'chapter_uuid' => $otherChapter->uuid,
    ])->assertOk();

    $recording->refresh();

    expect($recording->title)->toBe('مراجعة الوحدة الأولى')
        ->and((int) $recording->chapter_id)->toBe((int) $otherChapter->getKey())
        ->and((int) $recording->class_session_id)->toBe(4242);
});
