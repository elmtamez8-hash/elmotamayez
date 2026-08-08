<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * A session recording must not stand between a student and their certificate.
 *
 * A recording lesson is watched by whoever held a SEAT in that session — not by
 * whoever enrolled in the course (005 FR-030). Before this fix it sat in the
 * progress denominator anyway, so an enrolled student who was not in that room
 * carried a lesson they could never open: never 100%, `shouldCompleteCourse()`
 * never true, `CourseCompleted` never dispatched, certificate never issued. Not
 * late — never.
 *
 * **The recording is placed MID-tree in every case here, and that is the point.**
 * At the end of the tree it stands in front of nothing, so the same test passes
 * against the broken code and proves the opposite of what it claims.
 */
function courseWithRecordingInTheMiddle(int $workspaceId): array
{
    $course = Course::factory()->published()->create([
        'workspace_id' => $workspaceId,
        'is_sequential' => true,
    ]);

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 0,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'الفصل', 'status' => ContentStatus::Published, 'order' => 0,
    ]);

    $make = function (string $title, int $order, ?int $sessionId = null) use ($workspaceId, $course, $section, $chapter): Lesson {
        return Lesson::create([
            'workspace_id' => $workspaceId, 'course_id' => $course->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => $title, 'type' => 'article',
            'status' => ContentStatus::Published, 'content' => 'x',
            'class_session_id' => $sessionId, 'order' => $order,
        ]);
    };

    $profile = TeacherProfile::factory()->create(['workspace_id' => $workspaceId]);
    $session = ClassSession::factory()->create([
        'workspace_id' => $workspaceId,
        'teacher_profile_id' => $profile->id,
        'course_id' => $course->id,
    ]);

    $first = $make('الدرس الأول', 0);
    // Position 1 of 3: it stands in front of the third lesson.
    $recording = $make('تسجيل الحصة', 1, (int) $session->getKey());
    $last = $make('الدرس الأخير', 2);

    return [$course, $first, $recording, $last];
}

it('leaves a session recording out of the progress denominator', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [$course, $first, , $last] = courseWithRecordingInTheMiddle($workspace->id);

    $student = $this->addWorkspaceMember($workspace, 'student');
    Sanctum::actingAs($student);

    $enrollment = Enrollment::create([
        'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
    ]);

    $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$first->uuid}/complete")->assertOk();

    // Two countable lessons, one done. With the recording counted it would be 33%.
    expect($enrollment->refresh()->progress_pct)->toBe(50);
});

it('does not let a recording block the lesson after it for a student with no seat', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [$course, $first, , $last] = courseWithRecordingInTheMiddle($workspace->id);

    $student = $this->addWorkspaceMember($workspace, 'student');

    $enrollment = Enrollment::create([
        'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
    ]);

    Sanctum::actingAs($student);

    // The recording sits between them and it can never be completed by this
    // student. If it counted as a prerequisite, the last lesson would be shut
    // for good.
    expect($enrollment->canAccessLesson($last))->toBeFalse();

    $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$first->uuid}/complete")->assertOk();

    expect($enrollment->refresh()->canAccessLesson($last))->toBeTrue();
});

it('completes the course and issues the certificate without the recording', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    [$course, $first, , $last] = courseWithRecordingInTheMiddle($workspace->id);

    $student = $this->addWorkspaceMember($workspace, 'student');
    Sanctum::actingAs($student);

    $enrollment = Enrollment::create([
        'workspace_id' => $workspace->id, 'uuid' => Str::uuid(), 'course_id' => $course->id,
        'student_user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
    ]);

    $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$first->uuid}/complete")->assertOk();

    $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$last->uuid}/complete")
        ->assertOk()
        ->assertJsonPath('course_completed', true);

    expect($enrollment->refresh()->progress_pct)->toBe(100)
        ->and($enrollment->status)->toBe('completed');
});

it('refuses a lesson from another course through your own enrolment', function (): void {
    [$workspace] = test()->createWorkspaceWithOwner();
    $student = test()->addWorkspaceMember($workspace, 'student');

    Sanctum::actingAs($student);

    $mine = Course::factory()->published()->create(['workspace_id' => $workspace->id]);
    $theirs = Course::factory()->published()->create(['workspace_id' => $workspace->id]);

    $enrollment = Enrollment::factory()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $mine->id,
        'student_user_id' => $student->id,
    ]);

    $section = Section::create([
        'workspace_id' => $workspace->id, 'course_id' => $theirs->id,
        'title' => 'S', 'status' => ContentStatus::Published, 'order' => 0,
    ]);
    $chapter = Chapter::create([
        'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $theirs->id,
        'title' => 'C', 'status' => ContentStatus::Published, 'order' => 0,
    ]);
    $stranger = Lesson::create([
        'workspace_id' => $workspace->id, 'course_id' => $theirs->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'درس غريب', 'type' => 'article',
        'status' => ContentStatus::Published, 'content' => 'سرّي', 'order' => 0,
    ]);

    // `completeLesson` always checked this and `showLesson` never did, so the
    // body of any lesson was readable through an enrolment of one's own —
    // `canAccessLesson` answers about sequence, not about which course.
    test()->getJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$stranger->uuid}")
        ->assertNotFound();
});
