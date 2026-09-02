<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\CourseProgress;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;

/*
| Spec 023 · T051 — FR-019ب. A private session's recording is published into the
| course tree like any other, and it stays OUT of the progress denominator.
|
| ⚠️ THE PROPERTY HOLDS BY CONSTRUCTION AND IS A COINCIDENCE UNTIL A TEST SAYS
| SO. `progressEligible()` drops any lesson carrying a `class_session_id`, so
| nothing in 023 had to be written for this to be true — which is exactly the
| shape of a rule that gets removed by somebody who cannot see what it was for.
|
| ⚠️ AND THE FAILURE IT PREVENTS IS THE WORST ONE THIS TREE RECORDS. An item that
| enters the denominator and CAN NEVER BE COMPLETED caps every enrolled student
| below 100% for ever: `CourseCompleted` never fires and no certificate ever
| issues, with nothing logged. A private recording is entitled by a SEAT in one
| student's own hour — so for every other student in the course it is precisely
| that item, and there is no action any of them could take.
*/

it('leaves the denominator alone when a private session is published into the tree', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();

    [$before, $after] = app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): array {
        $course = $fx['course'];

        $section = Section::create([
            'workspace_id' => $fx['workspace']->getKey(), 'course_id' => $course->getKey(),
            'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 0,
        ]);

        $chapter = Chapter::create([
            'workspace_id' => $fx['workspace']->getKey(), 'section_id' => $section->getKey(),
            'course_id' => $course->getKey(), 'title' => 'الفصل',
            'status' => ContentStatus::Published, 'order' => 0,
        ]);

        $make = fn (string $title, int $order, ?int $sessionId): Lesson => Lesson::create([
            'workspace_id' => $fx['workspace']->getKey(), 'course_id' => $course->getKey(),
            'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
            'title' => $title, 'type' => 'video', 'status' => ContentStatus::Published,
            'order' => $order, 'class_session_id' => $sessionId,
        ]);

        $make('الدرس الأول', 0, null);
        $make('الدرس الثاني', 1, null);

        $enrollment = Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('student_user_id', $fx['student']->getKey())
            ->firstOrFail();

        $before = CourseProgress::total($enrollment);

        // A private hour, recorded and published — the shape FR-019ب describes.
        $session = ClassSession::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'teacher_profile_id' => $fx['profile']->getKey(),
            'course_id' => $course->getKey(),
            'seats_total' => 1,
        ]);

        $make('تسجيل الحصة الخاصة', 2, (int) $session->getKey());

        return [$before, CourseProgress::total($enrollment)];
    });

    // Three published lessons in the tree, two of them countable — and a student
    // who never sat that hour can still reach 100%.
    expect($before)->toBe(2)->and($after)->toBe(2);
});
