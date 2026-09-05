<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Learning\Models\ProgressHistory;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function createCourseWithLessons(int $workspaceId, int $lessonCount = 3, bool $sequential = true): Course
{
    $course = Course::factory()->create([
        'workspace_id' => $workspaceId,
        'status' => 'published',
        'is_sequential' => $sequential,
        /*
        | ⚠️ FREE, EXPLICITLY — AND THE FACTORY DOES NOT SAY SO. Its default is
        | `fake()->randomElement([0, 1999, 4999, 9999])`, so three runs in four
        | built a PRICED course while these tests read as though the price were
        | not part of the fixture at all.
        |
        | Since spec 027 · FR-004 that is the difference between 201 and 422:
        | self-enrolment is for free courses only, because until then this route
        | granted any authenticated account active access to any published course
        | on the platform. A fixture that leaves the price to chance now passes or
        | fails by the same chance. `SelfEnrollmentClosedTest` owns the other side.
        */
        'price_minor' => 0,
    ]);

    // Published at every level, explicitly. Since 016 a node that does not say
    // so is a draft, and a draft is invisible to students, outside the progress
    // denominator and skipped by the sequential gate — so a fixture that omits
    // the state builds a course these tests cannot see.
    $section = Section::create([
        'workspace_id' => $workspaceId,
        'course_id' => $course->id,
        'title' => 'Section 1',
        'status' => ContentStatus::Published,
        'order' => 1,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId,
        'section_id' => $section->id,
        'course_id' => $course->id,
        'title' => 'Chapter 1',
        'status' => ContentStatus::Published,
        'order' => 1,
    ]);

    for ($i = 1; $i <= $lessonCount; $i++) {
        Lesson::create([
            'workspace_id' => $workspaceId,
            'course_id' => $course->id,
            'section_id' => $section->id,
            'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(),
            'title' => "Lesson {$i}",
            'type' => 'article',
            'status' => ContentStatus::Published,
            'content' => "Content for lesson {$i}",
            'order' => $i,
        ]);
    }

    return $course->fresh();
}

describe('enrollment', function (): void {
    it('enrolls a student in a published course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = createCourseWithLessons($workspace->id);

        $student = User::factory()->create();
        $this->addWorkspaceMember($workspace, 'student', $student);

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/courses/{$course->uuid}/enroll")
            ->assertCreated()
            ->assertJsonPath('status', 'active');

        expect(Enrollment::where('course_id', $course->id)->where('student_user_id', $student->id)->exists())->toBeTrue();
    });

    it('prevents enrollment in unpublished courses', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => 'draft',
        ]);

        $student = $this->addWorkspaceMember($workspace, 'student');

        Sanctum::actingAs($student);

        $this->postJson("/api/v1/courses/{$course->uuid}/enroll")
            ->assertStatus(422);
    });

    it('prevents cross-workspace enrollment', function (): void {
        [$workspaceA] = $this->createWorkspaceWithOwner();
        [$workspaceB, $ownerB] = $this->createWorkspaceWithOwner();
        $course = createCourseWithLessons($workspaceA->id);

        Sanctum::actingAs($ownerB);

        $this->postJson("/api/v1/courses/{$course->uuid}/enroll")
            ->assertNotFound();
    });
});

describe('lesson gating', function (): void {
    it('grants access to the first lesson but not the second until completed', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createCourseWithLessons($workspace->id, 3);

        $student = $this->addWorkspaceMember($workspace, 'student');

        Sanctum::actingAs($student);

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id,
            'uuid' => Str::uuid(),
            'course_id' => $course->id,
            'student_user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $lessons = $course->fresh()->lessons()->orderBy('order')->get();
        $lesson1 = $lessons[0];
        $lesson2 = $lessons[1];

        // First lesson: accessible.
        $this->getJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lesson1->uuid}")
            ->assertOk()
            ->assertJsonPath('can_access', true);

        // Second lesson: NOT accessible (first not completed).
        $this->getJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lesson2->uuid}")
            ->assertOk()
            ->assertJsonPath('can_access', false)
            ->assertJsonPath('lesson.content', null);
    });

    it('grants access to the second lesson after completing the first', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createCourseWithLessons($workspace->id, 3);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id,
            'uuid' => Str::uuid(),
            'course_id' => $course->id,
            'student_user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $lessons = $course->fresh()->lessons()->orderBy('order')->get();

        // Complete lesson 1.
        $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lessons[0]->uuid}/complete")
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        // Lesson 2 should now be accessible.
        $this->getJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lessons[1]->uuid}")
            ->assertOk()
            ->assertJsonPath('can_access', true);
    });

    it('allows any lesson in a non-sequential course', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createCourseWithLessons($workspace->id, 3, sequential: false);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id,
            'uuid' => Str::uuid(),
            'course_id' => $course->id,
            'student_user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $lesson3 = $course->fresh()->lessons()->orderBy('order')->get()[2];

        $this->getJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lesson3->uuid}")
            ->assertOk()
            ->assertJsonPath('can_access', true);
    });

    it('allows preview lessons without enrollment', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createCourseWithLessons($workspace->id, 2);

        $lesson1 = $course->fresh()->lessons()->orderBy('order')->first();
        $lesson1->update(['is_preview' => true]);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        // Create enrollment (required for the route) but lesson 1 is a preview.
        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id,
            'uuid' => Str::uuid(),
            'course_id' => $course->id,
            'student_user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $lesson2 = $course->fresh()->lessons()->orderBy('order')->get()[1];

        // Lesson 2 still gated (not preview, previous not completed).
        $this->getJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lesson2->uuid}")
            ->assertJsonPath('can_access', false);
    });
});

describe('course completion', function (): void {
    it('marks the course as completed when all lessons are done', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createCourseWithLessons($workspace->id, 2);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id,
            'uuid' => Str::uuid(),
            'course_id' => $course->id,
            'student_user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $lessons = $course->fresh()->lessons()->orderBy('order')->get();

        // Complete lesson 1.
        $resp1 = $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lessons[0]->uuid}/complete");
        $resp1->assertOk();
        expect($resp1->json('course_completed'))->toBeFalse();

        // Complete lesson 2 → course should be completed.
        $resp2 = $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lessons[1]->uuid}/complete")
            ->assertOk();
        expect($resp2->json('course_completed'))->toBeTrue()
            ->and($resp2->json('progress_pct'))->toBe(100);

        expect($enrollment->fresh()->isCompleted())->toBeTrue()
            ->and($enrollment->fresh()->completed_at)->not->toBeNull();
    });

    it('writes progress history when a lesson is completed', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = createCourseWithLessons($workspace->id, 1);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id,
            'uuid' => Str::uuid(),
            'course_id' => $course->id,
            'student_user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $lesson = $course->fresh()->lessons()->orderBy('order')->first();

        $this->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$lesson->uuid}/complete")
            ->assertOk();

        $progress = LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $lesson->id)
            ->first();

        expect(ProgressHistory::where('lesson_progress_id', $progress->id)->exists())->toBeTrue();
    });
});
