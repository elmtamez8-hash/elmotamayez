<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\QuestionOption;
use Illuminate\Support\Str;

/*
| NFR-011 · SC-021. Two writes that arrive together, and what stops the second.
|
| ⚠️ EVERY TEST HERE SUBMITS TWICE FOR REAL. One submit that succeeds proves the
| happy path and nothing else — the guard being tested only ever runs on the
| second call, so a test that never makes one passes for ever against no guard at
| all. That is how this defect survived from spec 003: `AttemptController` read
| `isGraded()` and then `GradeAttempt` wrote, and the suite was green.
|
| What a duplicated answer set costs after spec 008 is worse than a duplicate
| row: the mistake notebook counts the same mistake twice, and `wrong_pct` is
| computed over a doubled denominator — a number that decides whether a teacher
| deletes a question from their bank.
*/

function concurrencyExam(int $workspaceId): array
{
    $exam = Exam::create([
        'workspace_id' => $workspaceId,
        'uuid' => Str::uuid(),
        'title' => 'اختبار التزامن',
        'passing_score' => 50,
        'max_attempts' => 2,
        'status' => 'published',
    ]);

    $workspace = $exam->workspace;
    $correct = [];

    foreach ([1, 2] as $i) {
        $question = bankQuestion($workspace, $exam, ['content' => "سؤال {$i}؟"]);

        $right = QuestionOption::create([
            'workspace_id' => $workspaceId, 'question_id' => $question->id,
            'content' => 'صحيح', 'is_correct' => true, 'order' => 1,
        ]);
        QuestionOption::create([
            'workspace_id' => $workspaceId, 'question_id' => $question->id,
            'content' => 'خطأ', 'is_correct' => false, 'order' => 2,
        ]);

        $correct[$question->id] = $right->id;
    }

    return [$exam, $correct];
}

it('accepts one submission of an attempt and refuses the second', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, 'student');
    [$exam, $correct] = concurrencyExam((int) $workspace->id);

    $attempt = app(StartAttempt::class)->handle($exam, $student);

    $answers = [];
    foreach ($correct as $questionId => $optionId) {
        $answers[] = ['question_id' => $questionId, 'selected_option_ids' => [$optionId]];
    }

    app(GradeAttempt::class)->handle($attempt, $answers);

    // The second arrival. It must not write, and it must say so rather than
    // failing on a database constraint further down.
    expect(fn () => app(GradeAttempt::class)->handle($attempt->fresh(), $answers))
        ->toThrow(DomainException::class);

    // Two questions, two answer rows. Four would mean both writers ran.
    expect(Answer::where('attempt_id', $attempt->id)->count())->toBe(2);
});

it('does not let a practice run eat an official attempt', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, 'student');
    [$exam] = concurrencyExam((int) $workspace->id);

    // Three revision sittings against an allowance of two.
    foreach (range(1, 3) as $ignored) {
        app(StartAttempt::class)->handle($exam, $student, null, isPractice: true);
    }

    // Both official attempts still available (FR-026أ).
    app(StartAttempt::class)->handle($exam, $student);
    app(StartAttempt::class)->handle($exam, $student);

    expect(fn () => app(StartAttempt::class)->handle($exam, $student))
        ->toThrow(DomainException::class);

    expect(Attempt::where('exam_id', $exam->id)->where('is_practice', false)->count())->toBe(2)
        ->and(Attempt::where('exam_id', $exam->id)->where('is_practice', true)->count())->toBe(3);
});

it('writes an answer row for a question the student skipped', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, 'student');
    [$exam, $correct] = concurrencyExam((int) $workspace->id);

    $attempt = app(StartAttempt::class)->handle($exam, $student);

    // Answer the first, leave the second untouched — not "answered wrongly",
    // absent from the payload entirely, which is what a skipped question is.
    $first = array_key_first($correct);
    app(GradeAttempt::class)->handle($attempt, [
        ['question_id' => $first, 'selected_option_ids' => [$correct[$first]]],
    ]);

    /*
     | ⚠️ THE SKIPPED QUESTION GETS A ROW, AND THE MISTAKE NOTEBOOK DEPENDS ON IT.
     | The notebook is derived from `is_correct = false`; the old loop walked the
     | submitted payload, so the question nobody answered produced no row and the
     | notebook could not see it. It is the strongest evidence of a gap on the
     | page — whoever skipped it did not know where to start.
     */
    expect(Answer::where('attempt_id', $attempt->id)->count())->toBe(2)
        ->and(Answer::where('attempt_id', $attempt->id)->where('is_correct', false)->count())->toBe(1);
});
