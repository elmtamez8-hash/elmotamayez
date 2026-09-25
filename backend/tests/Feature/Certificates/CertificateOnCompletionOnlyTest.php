<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Enums\ExamGate;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Actions\MarkLessonComplete;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ Owner decision, 2026-09-25: the course certificate is issued ONLY when the
| course is completed (`CourseCompleted`), never because an exam was passed.
|
| Removing the exam-pass issuance is safe only if a course whose LAST item is an
| exam still completes when that exam is passed. For a machine-marked paper it
| always did — the pass is written inside `GradeAttempt`'s transaction and the
| item listener runs after the commit. For a paper carrying an essay it did NOT:
| the paper submits as `pending_grading`, `ExamSubmitted` finds a pass-gated item
| unmet, and the pass that arrives when a person marks the essay re-ran nothing.
| It went unnoticed because the certificate issued on the pass itself. These
| cases pin both halves.
*/

/**
 * A sequential course: an article, then the exam as its last item.
 *
 * @return array{0: Course, 1: Lesson, 2: Enrollment}
 */
function completionOnlyCourse(Workspace $workspace, Exam $exam, User $student, ExamGate $gate): array
{
    $course = Course::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'is_sequential' => true,
    ]);

    $exam->update(['course_id' => $course->id]);

    $section = Section::create([
        'workspace_id' => $workspace->id, 'course_id' => $course->id,
        'title' => 'الوحدة', 'status' => ContentStatus::Published, 'order' => 1,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
        'title' => 'الفصل', 'status' => ContentStatus::Published, 'order' => 1,
    ]);

    $article = Lesson::create([
        'workspace_id' => $workspace->id, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'الدرس', 'type' => 'article', 'content' => 'نصّ',
        'status' => ContentStatus::Published, 'order' => 1,
    ]);

    Lesson::create([
        'workspace_id' => $workspace->id, 'course_id' => $course->id,
        'section_id' => $section->id, 'chapter_id' => $chapter->id,
        'uuid' => Str::uuid(), 'title' => 'الاختبار النهائي', 'type' => 'exam',
        'reference_id' => $exam->id, 'exam_gate' => $gate,
        'status' => ContentStatus::Published, 'order' => 2,
    ]);

    $enrollment = test()->createEnrollment($workspace, $course, $student);

    return [$course, $article, $enrollment];
}

function completionOnlyExam(Workspace $workspace, bool $withEssay): Exam
{
    $exam = Exam::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => 'published',
        'passing_score' => 60,
        'max_attempts' => 3,
    ]);

    if ($withEssay) {
        bankQuestion($workspace, $exam, ['type' => 'essay', 'points' => 10, 'content' => 'اشرح قانون نيوتن الأول.']);
    }

    $mcq = practiceQuestion($workspace, 'واحدٌ زائد واحد يساوي اثنين؟');
    ExamItem::create([
        'workspace_id' => $workspace->id,
        'exam_id' => $exam->id,
        'question_id' => $mcq->id,
        'order' => 99,
    ]);

    return $exam;
}

/** @return list<array<string, mixed>> */
function completionOnlyAnswers(Exam $exam): array
{
    $payload = [];

    foreach ($exam->items()->with('question.options')->get() as $item) {
        $question = $item->question;

        $payload[] = $question->type === 'essay'
            ? ['question_id' => $question->id, 'answer_text' => 'الجسمُ يبقى على حاله ما لم تؤثّر فيه قوّة.']
            : ['question_id' => $question->id, 'selected_option_ids' => [(int) $question->options->firstWhere('is_correct', true)->id]];
    }

    return $payload;
}

it('certifies a course whose last item is a machine-marked exam, on completion', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $exam = completionOnlyExam($workspace, withEssay: false);
    [, $article, $enrollment] = completionOnlyCourse($workspace, $exam, $student, ExamGate::Pass);

    app(MarkLessonComplete::class)->handle($enrollment, (int) $article->id);

    $attempt = app(StartAttempt::class)->handle($exam, $student);
    app(GradeAttempt::class)->handle($attempt, completionOnlyAnswers($exam));

    $certificate = Certificate::where('enrollment_id', $enrollment->id)->sole();

    expect($certificate->issue_reason)->toBe('course_completed')
        ->and((float) $enrollment->fresh()->progress_pct)->toBe(100.0);
});

it('certifies a course ending in a pass-gated essay exam once the essay is marked', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $exam = completionOnlyExam($workspace, withEssay: true);
    [, $article, $enrollment] = completionOnlyCourse($workspace, $exam, $student, ExamGate::Pass);

    app(MarkLessonComplete::class)->handle($enrollment, (int) $article->id);

    $attempt = app(StartAttempt::class)->handle($exam, $student);
    app(GradeAttempt::class)->handle($attempt, completionOnlyAnswers($exam));

    // Handed in, not yet passed: the item is unmet, the course is not complete,
    // and nothing is certified on half a paper.
    expect($attempt->fresh()->status)->toBe(Attempt::STATUS_PENDING_GRADING)
        ->and(Certificate::where('enrollment_id', $enrollment->id)->exists())->toBeFalse()
        ->and((float) $enrollment->fresh()->progress_pct)->toBeLessThan(100.0);

    Sanctum::actingAs($owner);

    $essay = Answer::query()
        ->where('attempt_id', $attempt->id)
        ->whereHas('question', fn ($q) => $q->where('type', 'essay'))
        ->sole();

    $this->postJson("/api/v1/manage/grading/answers/{$essay->uuid}", [
        'marks' => [['points' => 10]],
    ])->assertOk();

    // The pass arrives with the person's mark; the item completes on it, the
    // course completes on the item, and the certificate follows the course.
    $certificate = Certificate::where('enrollment_id', $enrollment->id)->sole();

    expect($attempt->fresh()->passed)->toBeTrue()
        ->and($certificate->issue_reason)->toBe('course_completed')
        ->and((float) $enrollment->fresh()->progress_pct)->toBe(100.0);
});

it('issues nothing when the exam is passed with the course still unfinished', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $exam = completionOnlyExam($workspace, withEssay: false);

    // Non-sequential here so the exam can be sat before the article: the week-one
    // quiz at partial progress that used to certify the whole course.
    [$course, , $enrollment] = completionOnlyCourse($workspace, $exam, $student, ExamGate::Pass);
    $course->update(['is_sequential' => false]);

    $attempt = app(StartAttempt::class)->handle($exam, $student);
    $graded = app(GradeAttempt::class)->handle($attempt, completionOnlyAnswers($exam));

    expect($graded->passed)->toBeTrue()
        ->and(Certificate::where('enrollment_id', $enrollment->id)->exists())->toBeFalse();
});
