<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\ConceptMastery;
use App\Modules\Tenancy\Support\PlatformSettings;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · FR-003 · FR-007. Mastery is a ROW, and it is not recomputed.
|
| ⚠️ THIS IS A DELIBERATE DEPARTURE FROM THIS REPOSITORY'S PREFERENCE FOR DERIVING,
| and the test is what says so out loud. The threshold is a `platform_settings`
| row an operator edits — so a derived mastery means raising it from three to four
| silently withdraws mastery from every student who had already earned it,
| retroactively, overnight, with nothing in any log. The two `threshold_*` columns
| are what let a reader a year later say on what bar it was granted.
*/

/** Drive one session to mastery and hand back its uuid. */
function masterOneConcept(array $fx): string
{
    Sanctum::actingAs($fx['student']);
    test()->asGuest();

    $start = test()->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    $question = $start['question'];

    foreach (range(1, 8) as $ignored) {
        $step = test()->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
            'question_id' => $question['question_id'],
            'option_ids' => [adaptiveRightOption($question['question_id'])],
        ])->assertOk()->json('data');

        if ($step['question'] === null) {
            break;
        }

        $question = $step['question'];
    }

    return $start['session']['uuid'];
}

it('records the criterion it was granted on', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'easy', 'easy', 'easy']);

    masterOneConcept($fx);

    $mastery = ConceptMastery::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())
        ->firstOrFail();

    expect($mastery->threshold_correct)->toBe(3)
        ->and($mastery->threshold_difficulty->value)->toBe('easy')
        ->and($mastery->mastered_at)->not->toBeNull()
        // The bridge carries the teacher's workspace for context, exactly as
        // `enrollments` does — the student is the platform's.
        ->and((int) $mastery->workspace_id)->toBe((int) $fx['workspace']->getKey());
});

/*
| ⚠️ RAISING THE BAR AFTERWARDS TAKES NOTHING AWAY. This is the assertion the
| whole "store it" decision exists for, and a derived implementation fails it
| without a line of code changing.
*/
it('keeps a mastery that was earned when the threshold is raised later', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'easy', 'easy', 'easy']);

    masterOneConcept($fx);

    PlatformSettings::set('assessments.adaptive.mastery_correct', 5);

    $mastery = ConceptMastery::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())
        ->firstOrFail();

    // The row stands, and it still reports the bar it was actually cleared at —
    // not the one in force today.
    expect($mastery->threshold_correct)->toBe(3);

    // And the concept list still shows it as mastered.
    $listed = $this->getJson('/api/v1/practice/adaptive/concepts')->assertOk()->json('data');

    expect(collect($listed)->firstWhere('uuid', $fx['concept']->uuid)['mastered_at'])->not->toBeNull();
});

/*
| One row per (student, concept) is what makes the award idempotent — the unique
| index is the guard, and `source_session_id` deliberately is NOT part of it: two
| sessions of the same concept differ in that column and would each write a row.
*/
it('writes one mastery row however many sessions reach the bar', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'easy', 'easy', 'easy', 'easy', 'easy']);

    masterOneConcept($fx);
    masterOneConcept($fx);

    expect(ConceptMastery::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())
        ->count())->toBe(1);
});
