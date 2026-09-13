<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Str;

/*
| ٠٣٥ · T074 · SC-008 — THE DENOMINATOR DID NOT MOVE.
|
| ⛔ AND THIS IS AN EXPLICIT SET, NOT A SENTENCE ABOUT ONE. «The denominator is
| unchanged» is not an assertion, and written as `progress_pct === 0` over a
| fixture whose items were never countable it stays green under ANY widening —
| which is exactly the shape of a guard that guards nothing. What is compared is
| the ID SET `countableForProgress()` returns against a list written out by hand.
|
| The three items in the fixture are the three a well-meaning widening drags in,
| and each one carries the same cost. This repository has recorded the family six
| times from six directions: AN ITEM THAT ENTERS THE DENOMINATOR AND CANNOT BE
| COMPLETED CAPS EVERY ENROLLED STUDENT BELOW 100% FOR EVER, so `CourseCompleted`
| never fires and no certificate is ever issued.
|
|  · a session RECORDING — entitled by a seat, not by enrolment, so a student who
|    was never in the room can never open it;
|  · an EXAM lesson tied to a session — the same gate wearing a different type;
|  · an ASSIGNMENT tied to a session — not a lesson at all, which is the reason
|    the denominator cannot see it, and the reason somebody will one day try.
|
| The last case is the other half of `accessTo()`: a locked recording must not
| stand in front of the item after it either. Counted OR chained, one without the
| other still bricks the course.
*/

/** @return array{course: Course, article: Lesson, countable: list<int>, after: Lesson, student: User} */
function denominatorFixture(): array
{
    $test = test();

    [$workspace, $owner] = $test->createWorkspaceWithOwner();
    $test->setCurrentWorkspace($workspace, $owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $owner->getKey()]);

    $course = Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $owner->getKey(),
    ]);

    $section = Section::create([
        'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
        'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 1,
    ]);

    // ⚠️ EVERY PARENT PINNED BY HAND. `ChapterFactory` and `LessonFactory` name
    // their parents as independent factories, so a bare call builds a node the
    // product cannot reach — and eight such rows reached the real database once.
    $chapter = Chapter::create([
        'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
        'course_id' => $course->getKey(),
        'title' => 'الفصل', 'status' => ContentStatus::Published, 'order' => 1,
    ]);

    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $course->getKey(),
    ]);

    $make = function (string $title, string $type, int $order, ?int $sessionId = null) use ($workspace, $course, $section, $chapter): Lesson {
        return Lesson::create([
            'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
            'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
            'uuid' => Str::uuid(), 'title' => $title, 'type' => $type,
            'content' => $type === 'article' ? 'نصّ' : null,
            'status' => ContentStatus::Published, 'order' => $order,
            'class_session_id' => $sessionId,
        ]);
    };

    $article = $make('مقالة', 'article', 1);
    $make('تسجيل حصة الأحد', 'video', 2, (int) $session->getKey());
    $make('امتحان الحصة', 'exam', 3, (int) $session->getKey());
    $make('ملاحظة', 'note', 4);
    $after = $make('الدرس الذي بعد التسجيل', 'video', 5);

    // A session-linked assignment, which lives in Assessments and is not a lesson.
    Assignment::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'class_session_id' => $session->getKey(),
    ]);

    $student = $test->addWorkspaceMember($workspace, Roles::STUDENT);
    $test->createEnrollment($workspace, $course, $student);
    $test->setCurrentWorkspace($workspace, $owner);

    return [
        'course' => $course,
        'article' => $article,
        // Written out by hand, on purpose. Derived from the same scope it is
        // meant to guard, this list would agree with any widening at all.
        'countable' => [(int) $article->getKey(), (int) $after->getKey()],
        'after' => $after,
        'student' => $student,
    ];
}

it('counts exactly the two items it counted before, and neither the recording nor the exam', function (): void {
    $fixture = denominatorFixture();

    $counted = $fixture['course']->lessons()
        ->countableForProgress()
        ->pluck('lessons.id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->sort()
        ->values()
        ->all();

    expect($counted)->toBe($fixture['countable']);
});

it('is unmoved by a session-linked assignment, which the denominator cannot even see', function (): void {
    $fixture = denominatorFixture();

    // Two, with an assignment sitting on the same session as the recording. An
    // assignment is a row in another module with its own `class_session_id`, so
    // widening the denominator to reach it is not a query change but a new join
    // — and the number here is what would move if anybody wrote one.
    expect($fixture['course']->lessons()->countableForProgress()->count())->toBe(2)
        ->and(Assignment::query()->whereNotNull('class_session_id')->count())->toBe(1);
});

it('lets a student open the item after a recording they were never in', function (): void {
    $fixture = denominatorFixture();

    $enrollment = Enrollment::query()
        ->where('course_id', $fixture['course']->getKey())
        ->where('student_user_id', $fixture['student']->getKey())
        ->sole();

    // Everything genuinely in front of the target, done. Without this the refusal
    // below is «finish the article first» — perfectly correct, and about the wrong
    // item, so the case would be red for a reason that has nothing to do with the
    // recording it is written for.
    app(MarkLessonComplete::class)->handle($enrollment, (int) $fixture['article']->getKey());

    /*
    | ⛔ THE OTHER HALF, AND EITHER ALONE STILL BRICKS THE COURSE. Out of the
    | denominator but left in the chain, a recording the student has no seat for
    | locks everything after it — the same permanent ceiling, reached from the
    | prerequisite side instead of the percentage side. The two items standing
    | between the article and the target are the recording and the session exam.
    */
    expect($enrollment->refresh()->accessTo($fixture['after'])->allowed)->toBeTrue();
});
