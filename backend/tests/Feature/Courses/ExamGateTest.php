<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Events\ExamSubmitted;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\LessonAccess;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * An exam placed in the middle of a sequential course, and the four ways that
 * can go (`SC-012` · `FR-042` · `FR-043`).
 *
 *  gate = يكفي أن يُحاول  × failed  → **opens**. The mark is feedback, not a door.
 *  gate = يكفي أن يُحاول  × passed  → opens.
 *  gate = يجب أن ينجح     × failed  → **blocked**, and told why and what fixes it.
 *  gate = يجب أن ينجح     × passed  → opens.
 *
 * Plus the two cases the four-way table hides: an attempt STARTED and abandoned
 * is not an attempt made, and an exam item nobody has touched blocks under the
 * weaker gate too — otherwise the weaker gate is no gate at all.
 */

/**
 * A sequential course: article → exam item → article. The middle item is the gate
 * and the last one is what the student is trying to reach.
 *
 * @return array{0: Course, 1: Enrollment, 2: Lesson, 3: Exam, 4: User}
 */
function gatedCourse(ExamGate $gate): array
{
    [$workspace, $owner] = test()->createWorkspaceWithOwner();
    $student = test()->addWorkspaceMember($workspace, 'student');

    Sanctum::actingAs($student);
    test()->setCurrentWorkspace($workspace, $student);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $gate, $student, $owner): array {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->id,
            'is_sequential' => true,
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

        $exam = Exam::factory()->published()->create([
            'workspace_id' => $workspace->id,
            'course_id' => $course->id,
            'title' => 'اختبار الوحدة الأولى',
            'passing_score' => 60,
        ]);

        $make = fn (string $title, string $type, int $order, array $extra = []): Lesson => Lesson::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id,
            'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => $title, 'type' => $type,
            'status' => ContentStatus::Published, 'order' => $order,
            ...$extra,
        ]);

        $first = $make('الدرس الأول', 'article', 1, ['content' => 'نصّ']);
        $make('اختبار الوحدة', 'exam', 2, ['reference_id' => $exam->id, 'exam_gate' => $gate]);
        $after = $make('الدرس الثاني', 'article', 3, ['content' => 'نصّ']);

        $enrollment = Enrollment::create([
            'workspace_id' => $workspace->id,
            'course_id' => $course->id,
            'student_user_id' => $student->id,
            'source' => 'manual',
            'status' => 'active',
            'progress_pct' => 0,
            'enrolled_at' => now(),
        ]);

        // The first lesson is done, so nothing but the exam stands between the
        // student and the item after it.
        $enrollment->progress()->create([
            'workspace_id' => $workspace->id,
            'lesson_id' => $first->id,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        return [$course, $enrollment, $after, $exam, $student];
    });
}

function sitExam(Exam $exam, Enrollment $enrollment, int $studentUserId, bool $passed, bool $submitted = true): Attempt
{
    return Attempt::create([
        'workspace_id' => $exam->workspace_id,
        'exam_id' => $exam->id,
        'enrollment_id' => $enrollment->id,
        'student_user_id' => $studentUserId,
        'status' => $submitted ? 'graded' : 'in_progress',
        'score' => $passed ? 80 : 20,
        'max_score' => 100,
        'passed' => $passed,
        'random_seed' => 1,
        'started_at' => now(),
        'submitted_at' => $submitted ? now() : null,
    ]);
}

it('opens the next item on a FAILED attempt when sitting it is enough', function (): void {
    [, $enrollment, $after, $exam, $student] = gatedCourse(ExamGate::Attempt);

    sitExam($exam, $enrollment, (int) $student->id, passed: false);

    // The whole point of the weaker gate: a wrong answer is information, not a
    // locked door. A student who fails still moves on.
    expect($enrollment->accessTo($after)->allowed)->toBeTrue();
});

it('opens the next item on a passed attempt when sitting it is enough', function (): void {
    [, $enrollment, $after, $exam, $student] = gatedCourse(ExamGate::Attempt);

    sitExam($exam, $enrollment, (int) $student->id, passed: true);

    expect($enrollment->accessTo($after)->allowed)->toBeTrue();
});

it('blocks the next item on a FAILED attempt when passing is required', function (): void {
    [, $enrollment, $after, $exam, $student] = gatedCourse(ExamGate::Pass);

    sitExam($exam, $enrollment, (int) $student->id, passed: false);

    $access = $enrollment->accessTo($after);

    expect($access->allowed)->toBeFalse()
        ->and($access->code)->toBe(LessonAccess::EXAM_PASS)
        // FR-043: the reason, and what undoes it. A lock with nothing after it
        // is a support ticket.
        ->and($access->message)->toContain('تجتاز')
        ->and($access->blockedByTitle)->toBe('اختبار الوحدة');
});

it('opens the next item on a passed attempt when passing is required', function (): void {
    [, $enrollment, $after, $exam, $student] = gatedCourse(ExamGate::Pass);

    sitExam($exam, $enrollment, (int) $student->id, passed: true);

    expect($enrollment->accessTo($after)->allowed)->toBeTrue();
});

it('does not count an attempt that was started and abandoned', function (): void {
    [, $enrollment, $after, $exam, $student] = gatedCourse(ExamGate::Attempt);

    // A row exists because the student opened the page. Treating that as having
    // sat the exam makes the weaker gate no gate at all.
    sitExam($exam, $enrollment, (int) $student->id, passed: false, submitted: false);

    $access = $enrollment->accessTo($after);

    expect($access->allowed)->toBeFalse()
        ->and($access->code)->toBe(LessonAccess::EXAM_ATTEMPT)
        ->and($access->message)->toContain('سلّم');
});

it('shows the student why the item is closed, through the API', function (): void {
    [, $enrollment, $after, $exam, $student] = gatedCourse(ExamGate::Pass);

    sitExam($exam, $enrollment, (int) $student->id, passed: false);

    $response = test()->getJson("/api/v1/learn/lessons/{$after->uuid}")->assertOk();

    expect($response->json('can_access'))->toBeFalse()
        ->and($response->json('blocked_reason'))->toBe(LessonAccess::EXAM_PASS)
        ->and($response->json('blocked_by_title'))->toBe('اختبار الوحدة')
        ->and($response->json('blocked_message'))->toContain('اختبار الوحدة');
});

it('refuses to complete a lesson the exam gate closes, with the same sentence', function (): void {
    [, $enrollment, $after, $exam, $student] = gatedCourse(ExamGate::Pass);

    sitExam($exam, $enrollment, (int) $student->id, passed: false);

    $response = test()->postJson("/api/v1/enrollments/{$enrollment->uuid}/lessons/{$after->uuid}/complete")
        ->assertStatus(422);

    // Not "You must complete the previous lesson first." — which was English,
    // and was also the wrong reason.
    expect($response->json('code'))->toBe(LessonAccess::EXAM_PASS)
        ->and($response->json('message'))->toContain('اختبار الوحدة');
});

it('completes the exam item itself when the exam is sat', function (): void {
    [$course, $enrollment, , $exam, $student] = gatedCourse(ExamGate::Attempt);

    // Without this the exam type is a trap: it is completable, so it enters the
    // denominator, and nothing could ever tick it off — so no course containing
    // an exam item could reach 100%, and no certificate could issue. Ever.
    $item = Lesson::query()->where('course_id', $course->id)->where('type', 'exam')->firstOrFail();

    expect($enrollment->progress()->where('lesson_id', $item->id)->exists())->toBeFalse();

    event(new ExamSubmitted(sitExam($exam, $enrollment, (int) $student->id, passed: false)));

    expect($enrollment->progress()->where('lesson_id', $item->id)->where('status', 'completed')->exists())
        ->toBeTrue();
});

it('does not complete an exam item that requires a pass when the student failed', function (): void {
    [$course, $enrollment, , $exam, $student] = gatedCourse(ExamGate::Pass);

    $item = Lesson::query()->where('course_id', $course->id)->where('type', 'exam')->firstOrFail();

    event(new ExamSubmitted(sitExam($exam, $enrollment, (int) $student->id, passed: false)));

    // Which of the two events counts is the ITEM's decision, not the listener's:
    // under "يجب أن ينجح" a failing submission finishes nothing.
    expect($enrollment->progress()->where('lesson_id', $item->id)->where('status', 'completed')->exists())
        ->toBeFalse();
});
