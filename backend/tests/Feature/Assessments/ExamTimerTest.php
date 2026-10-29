<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\CloseTimedOutAttempts;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\QuestionOption;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ THE SERVER OWNS THE EXAM CLOCK (owner decision 2026-10-29).
|
| The QA run submitted a one-minute paper seventy seconds in and was marked,
| and an open attempt resumed for ever. Now: answers are saved as they are
| chosen, nothing is taken a minute past the deadline, and a sitting the clock
| closed is marked on what was saved — by the sweep, by a late «سلّم», or by a
| resume, whichever comes first.
*/

/** @return array{0: Exam, 1: int, 2: int, 3: int} exam, question id, correct option id, wrong option id */
function timedExam(int $workspaceId, int $minutes = 10, int $attempts = 2): array
{
    $exam = Exam::query()->create([
        'workspace_id' => $workspaceId, 'uuid' => Str::uuid(), 'course_id' => null,
        'title' => 'Timed', 'duration_minutes' => $minutes, 'passing_score' => 50,
        'max_attempts' => $attempts, 'shuffle_questions' => false, 'shuffle_answers' => false,
        'status' => 'published',
    ]);

    $question = bankQuestion($exam->workspace, $exam, ['content' => 'Timed question?']);

    $right = QuestionOption::create(['workspace_id' => $workspaceId, 'question_id' => $question->id, 'content' => 'R', 'is_correct' => true, 'order' => 1]);
    $wrong = QuestionOption::create(['workspace_id' => $workspaceId, 'question_id' => $question->id, 'content' => 'W', 'is_correct' => false, 'order' => 2]);

    return [$exam, (int) $question->id, (int) $right->id, (int) $wrong->id];
}

beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    [$this->exam, $this->q, $this->right, $this->wrong] = timedExam((int) $this->workspace->getKey());

    $this->student = $this->addWorkspaceMember($this->workspace, 'student');
    Sanctum::actingAs($this->student);

    $started = $this->postJson("/api/v1/exams/{$this->exam->uuid}/attempts")->assertCreated();
    $this->uuid = (string) $started->json('attempt.uuid');

    expect($started->json('attempt.deadline_at'))->not->toBeNull()
        // The span, measured on the server — the screen counts from this, never
        // from `deadline_at` against the device's own (possibly wrong) clock.
        ->and($started->json('attempt.seconds_left'))->toBeBetween(595, 600);
});

it('marks a sitting the clock closed on the answers it saved', function (): void {
    $this->putJson("/api/v1/attempts/{$this->uuid}/answers", [
        'answers' => [['question_id' => $this->q, 'selected_option_ids' => [$this->right]]],
    ])->assertOk();

    // Inside the time: the sweep leaves it alone.
    $this->travel(5)->minutes();
    expect(app(CloseTimedOutAttempts::class)->handle())->toBe(0);

    $this->travel(7)->minutes();
    expect(app(CloseTimedOutAttempts::class)->handle())->toBe(1);

    $attempt = Attempt::query()->where('uuid', $this->uuid)->sole();
    expect($attempt->status)->toBe(Attempt::STATUS_GRADED)
        ->and((float) $attempt->score)->toBe(100.0);
});

it('takes a submission inside the grace and marks what it carries', function (): void {
    $this->travel(10)->minutes();
    $this->travel(30)->seconds();

    $this->postJson("/api/v1/attempts/{$this->uuid}/submit", [
        'answers' => [['question_id' => $this->q, 'selected_option_ids' => [$this->right]]],
    ])->assertOk()->assertJsonPath('score', 100);
});

it('marks a late «سلّم» on what was saved by the clock, never on what it carries', function (): void {
    $this->putJson("/api/v1/attempts/{$this->uuid}/answers", [
        'answers' => [['question_id' => $this->q, 'selected_option_ids' => [$this->wrong]]],
    ])->assertOk();

    $this->travel(12)->minutes();

    // Too late to save the correct answer…
    $this->putJson("/api/v1/attempts/{$this->uuid}/answers", [
        'answers' => [['question_id' => $this->q, 'selected_option_ids' => [$this->right]]],
    ])->assertStatus(409)->assertJsonPath('code', 'attempt_closed');

    // …and too late to submit it: the saved wrong answer is the paper.
    $this->postJson("/api/v1/attempts/{$this->uuid}/submit", [
        'answers' => [['question_id' => $this->q, 'selected_option_ids' => [$this->right]]],
    ])->assertOk()->assertJsonPath('score', 0);
});

it('closes an expired sitting on resume and starts the next one the allowance permits', function (): void {
    $this->travel(12)->minutes();

    $next = $this->postJson("/api/v1/exams/{$this->exam->uuid}/attempts")->assertCreated();

    expect($next->json('attempt.uuid'))->not->toBe($this->uuid)
        ->and(Attempt::query()->where('uuid', $this->uuid)->value('status'))->toBe(Attempt::STATUS_GRADED);

    // Both sittings spent: the third start is refused.
    $this->travel(12)->minutes();
    $this->postJson("/api/v1/exams/{$this->exam->uuid}/attempts")->assertStatus(422);
});

/*
| ⛔ A sitting begun before the server owned the clock is left alone — the first
| sweep would otherwise mark every weeks-old abandoned attempt ZERO at once.
*/
it('leaves a sitting from before the server clock untouched', function (): void {
    Attempt::query()->where('uuid', $this->uuid)->update(['clock_enforced' => false]);

    $this->travel(30)->days();

    expect(app(CloseTimedOutAttempts::class)->handle())->toBe(0)
        ->and(Attempt::query()->where('uuid', $this->uuid)->value('status'))->toBe(Attempt::STATUS_IN_PROGRESS);

    // And it still resumes, as it always did.
    $this->postJson("/api/v1/exams/{$this->exam->uuid}/attempts")->assertJsonPath('attempt.uuid', $this->uuid);
});

it('gives a reload the answers it saved', function (): void {
    $this->putJson("/api/v1/attempts/{$this->uuid}/answers", [
        'answers' => [['question_id' => $this->q, 'selected_option_ids' => [$this->right]]],
    ])->assertOk();

    $this->postJson("/api/v1/exams/{$this->exam->uuid}/attempts")
        ->assertJsonPath('attempt.uuid', $this->uuid)
        ->assertJsonPath('attempt.draft_answers.0.selected_option_ids', [$this->right]);
});
