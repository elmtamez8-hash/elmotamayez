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
| Spec 012 · SC-003 · FR-018 — a room scores nothing officially, and everything in
| the mistake notebook.
|
| ⚠️ IT CARRIES A POSITIVE CONTROL, AND WITHOUT ONE IT IS VACUOUS. «The room does
| not appear in the grade report» is satisfied by a report that is empty for every
| reason — a broken directory, the wrong workspace, a window that excludes today.
| So a REAL exam is sat and marked first, its number is asserted present, and only
| then is the room asserted absent from the same call.
*/

it('keeps a room out of the official grades while a real exam stays in', function (): void {
    $fx = studyRoomFixture(['easy', 'easy', 'easy']);

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

        $attempt = app(StartAttempt::class)->handle($exam, $fx['peer']);

        app(GradeAttempt::class)->handle($attempt, [[
            'question_id' => (int) $question->getKey(),
            'selected_option_ids' => [adaptiveRightOption((int) $question->getKey())],
        ]]);
    });

    $grades = fn (): array => app(StudentGradeDirectory::class)->officialGradesInPeriod(
        $fx['peer'],
        (int) $fx['workspace']->getKey(),
        CarbonImmutable::now()->subMonth(),
        CarbonImmutable::now()->addDay(),
    );

    $before = $grades();

    // ⚠️ THE CONTROL FIRES. Without this line every assertion below passes over a
    // report that was empty before the room was ever opened.
    expect($before['exams'])->not->toBeNull();

    $room = openStudyRoom($fx['student'], $fx['workspace'], ['question_count' => 3]);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    // Answer everything WRONGLY — the strongest case: a zero that must not enter
    // the report even though the paper was completed.
    foreach ($this->getJson("/api/v1/study-rooms/{$room['uuid']}")->json('data.questions') as $question) {
        $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", [
            'question_id' => $question['question_id'],
            'option_ids' => [adaptiveWrongOption($question['question_id'])],
        ])->assertOk();
    }

    expect($grades()['exams'])->toBe($before['exams']);
});

/*
| The other half, and it is the half a parallel table would have broken: the
| mistake notebook is derived from `exam_answers` alone, so a room answer written
| anywhere else would be invisible to it. A design that satisfied «keeps it out of
| the grades» with its own table satisfies half the requirement and silently drops
| the other.
*/
it('puts a wrong room answer into the mistake notebook', function (): void {
    $fx = studyRoomFixture(['easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], ['question_count' => 2]);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    $missed = $this->getJson("/api/v1/study-rooms/{$room['uuid']}")->json('data.questions.0.question_id');

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", [
        'question_id' => $missed,
        'option_ids' => [adaptiveWrongOption($missed)],
    ])->assertOk();

    // The notebook addresses a question by uuid, so the id answered in the room is
    // resolved rather than compared — matching on the wrong key would fail this
    // for a reason that has nothing to do with the feature.
    $uuid = (string) Question::query()->withoutWorkspaceScope()->whereKey($missed)->value('uuid');

    $uuids = collect(data_get($this->getJson('/api/v1/mistakes')->assertOk()->json(), 'data', []))
        ->pluck('question.uuid');

    expect($uuids)->toContain($uuid);
});
