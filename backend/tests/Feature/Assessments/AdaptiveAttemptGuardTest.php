<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Models\AdaptiveSession;
use App\Modules\Assessments\Models\Attempt;
use DomainException;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · T055. The door that would brick a session permanently.
|
| ⚠️ `GradeAttempt` WRITES AN ANSWER ROW FOR EVERY ITEM ON THE PAPER, and an
| adaptive attempt already carries one per question answered — so the collision on
| `unique(attempt_id, question_id)` is GUARANTEED, not unlikely.
|
| The 500 is only half of it. `claimForGrading()` sits OUTSIDE `DB::transaction()`,
| so the transaction rolls back and the claim does not: the attempt is left at
| `grading` with nothing in the tree able to move it out, the session can never be
| answered again, and there is no way back short of SQL.
|
| ⚠️ AND THE SESSION MUST STILL WORK AFTERWARDS. A guard that refused and left the
| attempt claimed would satisfy «it refuses» while doing the exact damage it
| exists to prevent — which is why the last assertion here answers another
| question through the API rather than reading a status and stopping.
*/

/** @return array{fx: array<string, mixed>, start: array<string, mixed>, attempt: Attempt} */
function adaptiveAttemptUnderGuard(): array
{
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    test()->asGuest();

    $start = test()->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    // One question answered the proper way, so the attempt carries rows.
    $step = test()->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $start['question']['question_id'],
        'option_ids' => [adaptiveRightOption($start['question']['question_id'])],
    ])->assertOk()->json('data');

    $session = AdaptiveSession::query()
        ->withoutWorkspaceScope()
        ->where('uuid', $start['session']['uuid'])
        ->firstOrFail();

    return [
        'fx' => $fx,
        'start' => ['session' => $start['session'], 'question' => $step['question']],
        'attempt' => Attempt::query()->withoutWorkspaceScope()->findOrFail($session->attempt_id),
    ];
}

it('refuses to mark an adaptive attempt as a whole paper, before claiming it', function (): void {
    ['start' => $start, 'attempt' => $attempt] = adaptiveAttemptUnderGuard();

    $answers = [[
        'question_id' => $start['question']['question_id'],
        'selected_option_ids' => [adaptiveRightOption($start['question']['question_id'])],
    ]];

    // A DomainException, which the controller renders as 422 — never a
    // QueryException, which is a 500 with a database message behind it.
    expect(fn () => app(GradeAttempt::class)->handle($attempt, $answers))
        ->toThrow(DomainException::class);

    /*
    | ⚠️ THE ATTEMPT IS STILL OPEN, AND THIS IS THE ASSERTION THAT MATTERS.
    | Refused AFTER the claim it would sit at `grading` for ever: the guard
    | standing before `claimForGrading()` is what makes the refusal free.
    */
    expect($attempt->fresh()->status)->toBe(Attempt::STATUS_IN_PROGRESS);

    // And the session answers its NEXT question normally — the real proof that
    // nothing was left half-done. A status read alone would not show it: the
    // brick this guards against is only visible when something tries to write.
    test()->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $start['question']['question_id'],
        'option_ids' => [adaptiveRightOption($start['question']['question_id'])],
    ])->assertOk();
});

/*
| ⚠️ AND THE HTTP DOOR IS OPEN, WHICH IS WHY THE GUARD ABOVE HAD TO EXIST.
|
| Until 2026-08-30 this route answered 403 to every student for an unrelated
| reason — `SubmitAttemptRequest::authorize()` asked `can(ATTEMPTS_SUBMIT)`, and a
| real student holds no role because they are a member of no workspace. That was a
| bug in its own right (see `RealStudentExamSubmitTest`), and while it stood it
| accidentally hid this door.
|
| It is fixed, so the door is reachable by exactly the person whose attempt this
| is — and `GradeAttempt`'s `exists()` guard is now the only thing between a
| student's second tap and an attempt stranded at `grading` for ever.
*/
it('refuses an adaptive attempt through the exam submit route, with a 422', function (): void {
    ['start' => $start, 'attempt' => $attempt] = adaptiveAttemptUnderGuard();

    test()->postJson("/api/v1/attempts/{$attempt->uuid}/submit", [
        'answers' => [[
            'question_id' => $start['question']['question_id'],
            'selected_option_ids' => [adaptiveRightOption($start['question']['question_id'])],
        ]],
    ])->assertStatus(422);

    // Still open, and still answerable — the refusal cost the session nothing.
    expect($attempt->fresh()->status)->toBe(Attempt::STATUS_IN_PROGRESS);

    test()->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $start['question']['question_id'],
        'option_ids' => [adaptiveRightOption($start['question']['question_id'])],
    ])->assertOk();
});
