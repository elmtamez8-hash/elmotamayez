<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Events\MistakeResolved;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\QuestionOption;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/*
| SC-007 · FR-016 · FR-020. Every mistake of theirs, none of anybody else's.
|
| ⚠️ THE NOTEBOOK IS DERIVED, so what has to be tested is the DERIVATION and not
| a stored flag: "fixed" means a later correct answer by the same student on the
| same question, and the word doing the work is LATER. A student who answered
| correctly once and wrongly since has fixed nothing — and the obvious query,
| `MAX(is_correct)`, reports that they have.
*/

it('shows every mistake of this student and none of another student', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $me = $this->addWorkspaceMember($workspace);
    $them = $this->addWorkspaceMember($workspace);

    $mine = bankQuestion($workspace, null, ['content' => 'خطئي أنا؟']);
    $theirs = bankQuestion($workspace, null, ['content' => 'خطأ غيري؟']);

    answerRow((int) $workspace->getKey(), $me, (int) $mine->getKey(), correct: false);
    answerRow((int) $workspace->getKey(), $them, (int) $theirs->getKey(), correct: false);

    Sanctum::actingAs($me);

    $response = $this->getJson('/api/v1/mistakes');

    $response->assertOk();

    expect(collect($response->json('data'))->pluck('question.content')->all())->toBe(['خطئي أنا؟']);
});

it('treats a later correct answer as a fix and an earlier one as nothing', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);

    $fixed = bankQuestion($workspace, null, ['content' => 'أصلحته؟']);
    $regressed = bankQuestion($workspace, null, ['content' => 'كنت أعرفه ثم نسيته؟']);

    answerRow((int) $workspace->getKey(), $student, (int) $fixed->getKey(), correct: false);
    answerRow((int) $workspace->getKey(), $student, (int) $fixed->getKey(), correct: true);

    // The other way round. `MAX(is_correct)` cannot tell these two apart.
    answerRow((int) $workspace->getKey(), $student, (int) $regressed->getKey(), correct: true);
    answerRow((int) $workspace->getKey(), $student, (int) $regressed->getKey(), correct: false);

    Sanctum::actingAs($student);

    $standing = collect($this->getJson('/api/v1/mistakes')->json('data'));

    expect($standing->pluck('question.content')->all())->toBe(['كنت أعرفه ثم نسيته؟']);

    // And the fixed one is still readable when asked for — it is a notebook, not
    // a to-do list, and re-reading a question you finally got is the point.
    $all = collect($this->getJson('/api/v1/mistakes?include_resolved=1')->json('data'));

    expect($all)->toHaveCount(2)
        ->and($all->firstWhere('question.content', 'أصلحته؟')['is_resolved'])->toBeTrue();
});

it('counts a correct answer inside a practice run as a fix', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $question = bankQuestion($workspace);

    answerRow((int) $workspace->getKey(), $student, (int) $question->getKey(), correct: false);

    // ⚠️ The opposite polarity from the US2 rollup, deliberately. Practising IS
    // how FR-019 says a mistake gets fixed; filter practice out here and the
    // revision loop hands back the same questions for ever.
    answerRow((int) $workspace->getKey(), $student, (int) $question->getKey(), correct: true, overrides: ['is_practice' => true]);

    Sanctum::actingAs($student);

    expect($this->getJson('/api/v1/mistakes')->json('data'))->toBe([]);
});

it('shows the question the student skipped entirely', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $question = bankQuestion($workspace, null, ['content' => 'تركته فارغاً؟']);

    QuestionOption::create([
        'workspace_id' => $workspace->getKey(),
        'question_id' => $question->getKey(),
        'content' => 'الجواب الصحيح',
        'is_correct' => true,
        'order' => 1,
    ]);

    // GradeAttempt writes a row for every question SHOWN, not for every question
    // answered — leaving one blank is the strongest evidence of a gap on the
    // page, and a notebook derived from `is_correct = false` would be blind to it
    // if the row were never written.
    answerRow((int) $workspace->getKey(), $student, (int) $question->getKey(), correct: false, overrides: [
        'selected_option_ids' => [],
    ]);

    Sanctum::actingAs($student);

    $row = $this->getJson('/api/v1/mistakes')->json('data.0');

    expect($row['question']['content'])->toBe('تركته فارغاً؟')
        ->and($row['your_answer'])->toBe([])
        // And the right answer is still shown. A blank with no correction is a
        // record of failure rather than a way to study.
        ->and($row['correct_answer'])->not->toBe([]);
});

it('does not put an essay nobody has marked in the notebook', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $essay = bankQuestion($workspace, null, ['type' => 'essay', 'content' => 'اشرح؟']);

    answerRow((int) $workspace->getKey(), $student, (int) $essay->getKey(), correct: false, overrides: [
        'requires_grading' => true,
        'graded_at' => null,
        'answer_text' => 'إجابتي المطوّلة',
    ]);

    Sanctum::actingAs($student);

    expect($this->getJson('/api/v1/mistakes')->json('data'))->toBe([]);
});

it('keeps one teacher notebook out of another teacher notebook', function (): void {
    [$first, $firstOwner] = $this->createWorkspaceWithOwner();
    [$second, $secondOwner] = $this->createWorkspaceWithOwner();

    // ⚠️ TWO WORKSPACES AND ONE STUDENT — the shape FR-016أ is about. With one
    // workspace this test passes whether the notebook is per teacher or merged
    // across every teacher the student studies with.
    $this->setCurrentWorkspace($second, $secondOwner);
    $student = $this->addWorkspaceMember($second);
    $hers = bankQuestion($second, null, ['content' => 'سؤال المدرّسة الثانية؟']);
    answerRow((int) $second->getKey(), $student, (int) $hers->getKey(), correct: false);

    $this->setCurrentWorkspace($first, $firstOwner);
    $this->addWorkspaceMember($first, user: $student);
    $his = bankQuestion($first, null, ['content' => 'سؤال المدرّس الأوّل؟']);
    answerRow((int) $first->getKey(), $student, (int) $his->getKey(), correct: false);

    Sanctum::actingAs($student);
    $this->setCurrentWorkspace($first, $student);

    expect(collect($this->getJson('/api/v1/mistakes')->json('data'))->pluck('question.content')->all())
        ->toBe(['سؤال المدرّس الأوّل؟']);
});

it('raises MistakeResolved from the grading path itself', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $workspaceId = (int) $workspace->getKey();

    $question = practiceQuestion($workspace, 'سؤالٌ سأصلحه؟');
    answerRow($workspaceId, $student, (int) $question->getKey(), correct: false);

    $exam = Exam::factory()->create(['workspace_id' => $workspaceId, 'status' => 'published']);
    $exam->items()->create([
        'workspace_id' => $workspaceId,
        'question_id' => $question->getKey(),
        'order' => 1,
    ]);

    // ⚠️ A PARTIAL FAKE. `Event::fake()` with no arguments swallows ExamSubmitted
    // and every certificate and notification listener behind it, and the test
    // then asserts about a submission that never finished.
    Event::fake([MistakeResolved::class]);

    $attempt = app(StartAttempt::class)->handle($exam, $student);
    $correctIds = $question->options->where('is_correct', true)->pluck('id')->all();

    app(GradeAttempt::class)->handle($attempt, [
        ['question_id' => (int) $question->getKey(), 'selected_option_ids' => $correctIds],
    ]);

    // 009 hangs a reward off this. It has no listener today, and raising it now
    // is what keeps that release out of the code that marks every paper.
    Event::assertDispatched(
        MistakeResolved::class,
        fn (MistakeResolved $event): bool => $event->studentId === (int) $student->getKey()
            && $event->questionId === (int) $question->getKey()
            && $event->workspaceId === $workspaceId,
    );
});
