<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · FR-006. The exam they have not sat yet is subtracted.
|
| ⚠️ THE GUARD IS `PracticePool`, REUSED AND NOT REIMPLEMENTED, and this test is
| what fails if the adaptive path ever grows a pool of its own. One bank question
| serves several exams, so a question the student will meet next week can also sit
| in this concept — practise it and the platform marks it instantly and shows the
| explanation, which is that exam with its answers handed over a week early.
*/

it('never serves a question that sits in a published exam the student has not sat', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'easy']);

    // Put ONE of the four into a published exam. Nothing else changes.
    $withheld = $fx['questions']->first();

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $withheld): void {
        $exam = Exam::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'status' => 'published',
            'max_attempts' => 3,
        ]);

        ExamItem::create([
            'workspace_id' => $fx['workspace']->getKey(),
            'exam_id' => $exam->getKey(),
            'question_id' => $withheld->getKey(),
            'order' => 1,
        ]);
    });

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    $seen = [$start['question']['question_id']];
    $question = $start['question'];

    // Walk the whole concept: the withheld one must not appear at any point.
    foreach (range(1, 6) as $ignored) {
        $step = $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
            'question_id' => $question['question_id'],
            'option_ids' => [adaptiveWrongOption($question['question_id'])],
        ])->assertOk()->json('data');

        if ($step['question'] === null) {
            break;
        }

        $question = $step['question'];
        $seen[] = $question['question_id'];
    }

    expect($seen)->not->toContain((int) $withheld->getKey())
        // ⚠️ THE POSITIVE CONTROL. Without it this passes over a session that
        // served nothing at all, which is a different bug wearing the same green.
        ->and(count($seen))->toBeGreaterThan(1);
});

/*
| The mirror: once they HAVE sat that exam, the question comes back. Withholding
| it afterwards protects nothing — they have already seen every question on the
| paper — and blocks revision of exactly the one they need.
*/
it('serves the question again once that exam has been sat', function (): void {
    $fx = adaptiveFixture(['easy', 'easy']);
    $withheld = $fx['questions']->first();

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $withheld): void {
        $exam = Exam::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'status' => 'published',
            'max_attempts' => 3,
        ]);

        ExamItem::create([
            'workspace_id' => $fx['workspace']->getKey(),
            'exam_id' => $exam->getKey(),
            'question_id' => $withheld->getKey(),
            'order' => 1,
        ]);

        $attempt = app(StartAttempt::class)->handle($exam, $fx['student']);

        app(GradeAttempt::class)->handle($attempt, [[
            'question_id' => (int) $withheld->getKey(),
            'selected_option_ids' => [adaptiveWrongOption((int) $withheld->getKey())],
        ]]);
    });

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $concepts = $this->getJson('/api/v1/practice/adaptive/concepts')->assertOk()->json('data');

    // Both questions are back in the pool, so the count reads two rather than one.
    expect(collect($concepts)->firstWhere('uuid', $fx['concept']->uuid)['question_count'])->toBe(2);
});
