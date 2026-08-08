<?php

declare(strict_types=1);

use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Withdrawing an item to draft removes it from the course. It does not remove
 * the record that a student did it.
 *
 * The distinction is the whole of FR-029. A teacher pulling a lesson back to fix
 * a typo is saying "stop showing this", not "erase the evidence that thirty
 * people completed it" — and certainly not "revoke the certificate that was
 * issued when they finished". Deletion is the destructive act, and it is refused
 * outright when progress exists (`TreeDeletionGuard`); unpublishing is the safe
 * one, which is exactly why it must stay safe.
 */
function safetyCourse(int $workspaceId): array
{
    $course = Course::factory()->published()->create(['workspace_id' => $workspaceId]);

    $section = Section::create([
        'workspace_id' => $workspaceId, 'course_id' => $course->id,
        'title' => 'قسم', 'status' => ContentStatus::Published, 'order' => 0,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'فصل', 'status' => ContentStatus::Published, 'order' => 0,
    ]);

    $lessons = [];

    foreach ([0, 1] as $index) {
        $lessons[] = Lesson::create([
            'workspace_id' => $workspaceId, 'course_id' => $course->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => "درس {$index}", 'type' => 'article',
            'status' => ContentStatus::Published, 'content' => 'نصّ', 'order' => $index,
        ]);
    }

    return [$course, $lessons];
}

it('keeps recorded progress when an item is withdrawn to draft', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');
    [$course, $lessons] = safetyCourse($workspace->id);

    $enrollment = Enrollment::factory()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'student_user_id' => $student->id,
    ]);

    LessonProgress::create([
        'workspace_id' => $workspace->id,
        'enrollment_id' => $enrollment->id,
        'lesson_id' => $lessons[0]->id,
        'is_completed' => true,
        'completed_at' => now(),
    ]);

    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->structure_version,
        'items' => [['uuid' => $lessons[0]->uuid, 'status' => 'draft']],
    ])->assertOk();

    // The row survives. It is the record of work someone did, and unpublishing
    // is a statement about the course, not about them.
    expect(LessonProgress::query()->where('lesson_id', $lessons[0]->id)->exists())->toBeTrue()
        ->and($lessons[0]->refresh()->status)->toBe(ContentStatus::Draft);
});

it('does not void an issued certificate', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $student = $this->addWorkspaceMember($workspace, 'student');
    [$course, $lessons] = safetyCourse($workspace->id);

    $enrollment = Enrollment::factory()->create([
        'workspace_id' => $workspace->id,
        'course_id' => $course->id,
        'student_user_id' => $student->id,
        'status' => 'completed',
        'progress_pct' => 100,
    ]);

    $certificate = Certificate::create([
        'workspace_id' => $workspace->id,
        'certificate_number' => 'CERT-'.Str::upper(Str::random(8)),
        'verification_code' => Str::upper(Str::random(12)),
        'enrollment_id' => $enrollment->id,
        'course_id' => $course->id,
        'student_user_id' => $student->id,
        'issue_reason' => 'course_completed',
        'issued_at' => now(),
    ]);

    Sanctum::actingAs($owner);

    $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->structure_version,
        'items' => [['uuid' => $lessons[0]->uuid, 'status' => 'draft']],
    ])->assertOk();

    // A certificate says what was true when it was earned. Editing the course
    // afterwards cannot make that untrue — and its public verification page is a
    // link people put on a CV.
    expect($certificate->refresh()->exists)->toBeTrue()
        ->and($enrollment->refresh()->status)->toBe('completed');
});

it('drops a withdrawn item out of the denominator', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    Sanctum::actingAs($owner);

    [$course, $lessons] = safetyCourse($workspace->id);

    // Read AFTER acting: WorkspaceContext caches its resolution on the first
    // call, so a tenant query before sign-in freezes it at null.
    expect(Lesson::query()->where('course_id', $course->id)->countableForProgress()->count())->toBe(2);

    $this->postJson("/api/v1/courses/{$course->uuid}/tree/publish", [
        'structure_version' => $course->structure_version,
        'items' => [['uuid' => $lessons[1]->uuid, 'status' => 'draft']],
    ])->assertOk();

    // Which is the point: a student mid-course is not left holding a percentage
    // measured against a lesson nobody can open any more.
    expect(Lesson::query()->where('course_id', $course->id)->countableForProgress()->count())->toBe(1);
});
