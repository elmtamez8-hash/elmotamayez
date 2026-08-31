<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use App\Shared\Contracts\StudentGradeDirectory;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · SC-003 · FR-005. A session scores nothing officially, and everything
| in the mistake notebook.
|
| ⚠️ IT CARRIES A POSITIVE CONTROL, AND WITHOUT ONE IT IS VACUOUS. «The adaptive
| session does not appear in the grade report» is satisfied by a report that is
| empty for every reason — a broken directory, a wrong workspace, a window that
| excludes today. So a REAL exam is sat and marked first, its number is asserted
| to be there, and only then is the session asserted absent from the same call.
*/

it('keeps a session out of the official grades while a real exam stays in', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'easy']);

    // The control: an official attempt on a published exam, marked.
    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): void {
        $exam = Exam::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'status' => 'published',
            'passing_score' => 50,
            'max_attempts' => 3,
        ]);

        $question = $fx['questions']->first();

        ExamItem::create([
            'workspace_id' => $fx['workspace']->getKey(),
            'exam_id' => $exam->getKey(),
            'question_id' => $question->getKey(),
            'order' => 1,
        ]);

        $attempt = app(StartAttempt::class)->handle($exam, $fx['student']);

        app(GradeAttempt::class)->handle($attempt, [[
            'question_id' => (int) $question->getKey(),
            'selected_option_ids' => [adaptiveRightOption((int) $question->getKey())],
        ]]);
    });

    $grades = fn (): array => app(StudentGradeDirectory::class)->officialGradesInPeriod(
        $fx['student'],
        (int) $fx['workspace']->getKey(),
        CarbonImmutable::now()->subMonth(),
        CarbonImmutable::now()->addDay(),
    );

    $before = $grades();

    // ⚠️ THE CONTROL FIRES. Without this line every assertion below passes over a
    // report that was empty before the session was ever opened.
    expect($before['exams'])->not->toBeNull();

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    // Answer wrongly — the strongest case: a zero that must not enter the report.
    $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $start['question']['question_id'],
        'option_ids' => [adaptiveWrongOption($start['question']['question_id'])],
    ])->assertOk();

    $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/end", [])->assertOk();

    expect($grades()['exams'])->toBe($before['exams']);
});

/*
| The other half of FR-005, and it is the half a parallel table would have broken:
| the mistake notebook is derived from `exam_answers` alone, so an adaptive answer
| that lived anywhere else would be invisible to it. A design that satisfied
| «keeps it out of the grades» with its own table satisfies half the requirement
| and silently drops the other.
*/
it('puts a wrong adaptive answer into the mistake notebook', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    $missed = $start['question']['question_id'];

    $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $missed,
        'option_ids' => [adaptiveWrongOption($missed)],
    ])->assertOk();

    $notebook = $this->getJson('/api/v1/mistakes')->assertOk()->json();

    // The notebook addresses a question by uuid, so the id served in the session
    // is resolved rather than compared — matching on the wrong key would make
    // this assertion fail for a reason that has nothing to do with the feature.
    $uuid = (string) Question::query()->withoutWorkspaceScope()->whereKey($missed)->value('uuid');

    $uuids = collect(data_get($notebook, 'data', []))->pluck('question.uuid');

    expect($uuids)->toContain($uuid);
});
