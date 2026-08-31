<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\AdaptiveSession;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012. Two writes that arrive together, and what stops the second.
|
| ⚠️ A SEQUENTIAL «START IT TWICE» TEST PASSES AGAINST A BUILD WITH NO CLAIM IN IT
| AT ALL — the second call returns at the cheap «you already have one» read a step
| earlier and never reaches the claim. The window is between that read and the
| INSERT, and `AdaptiveLadder` is asked inside it, so a hook on the ladder's own
| query IS the other worker winning: no threads, no sleeps, and it fails the
| moment the claim is removed.
*/

/** Run $rival exactly once, the first time a query containing $needle is issued. */
function interposeAdaptiveOnce(string $needle, Closure $rival): void
{
    $fired = false;

    DB::beforeExecuting(function (string $query) use (&$fired, $needle, $rival): void {
        // The guard is not decoration: the rival issues queries of its own, and
        // without it this recurses until the stack gives out.
        if ($fired || ! str_contains($query, $needle)) {
            return;
        }

        $fired = true;

        $rival();
    });
}

it('opens one session when a second start lands between the read and the claim', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $payload = [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ];

    $rivalRan = false;

    /*
    | `AdaptiveLadder::next()` orders by the distance from the wanted difficulty,
    | and that `abs(` is the one query issued between the running-session read and
    | the session INSERT. Firing here puts the rival exactly inside the window.
    */
    interposeAdaptiveOnce('abs(', function () use ($payload, &$rivalRan): void {
        $rivalRan = true;

        test()->postJson('/api/v1/practice/adaptive', $payload);
    });

    $response = $this->postJson('/api/v1/practice/adaptive', $payload);

    /*
    | ⚠️ THE CONTROL, AND IT IS NOT CEREMONY. If the needle stops matching — the
    | ladder's ordering changes, or a Laravel release rewrites the SQL — the hook
    | never fires, ONE start runs, and every assertion below is satisfied by a
    | build with no claim whatsoever.
    */
    expect($rivalRan)->toBeTrue();

    // One session, whichever of the two won it.
    expect(AdaptiveSession::query()->withoutWorkspaceScope()->count())->toBe(1);

    // And the loser cleaned up after itself: no orphan attempt left behind, which
    // is what makes the "write the attempt before the claim" ordering safe.
    expect(Attempt::query()->withoutWorkspaceScope()->count())->toBe(1);

    // The refusal is a conflict, and it carries the session so the client that
    // lost is put back into the one that exists rather than left with nothing.
    if ($response->status() === 409) {
        expect($response->json('data.session.uuid'))->not->toBeNull();
    } else {
        $response->assertCreated();
    }
});

/*
| ⚠️ THE SECOND ANSWER IS A CLEAN 409, NOT A 500. `unique(attempt_id, question_id)`
| alone turns a double tap into a database error — the migration that added that
| index says exactly this in its own words — so the refusal needs the atomic claim
| AND the index. `AnswerMarker`'s `insertOrIgnore` plus its read-back is the claim.
*/
it('refuses a second answer to the same question with a conflict and not an error', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    $question = $start['question']['question_id'];
    $body = ['question_id' => $question, 'option_ids' => [adaptiveRightOption($question)]];

    $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", $body)->assertOk();

    $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", $body)
        ->assertStatus(409);

    // One answer row. Two would double-count the mistake in the notebook and
    // double the denominator of every `wrong_pct` computed over it.
    expect(Answer::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $fx['student']->getKey())
        ->where('question_id', $question)
        ->count())->toBe(1);
});

it('refuses an answer to a question that was never served to this session', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    $unserved = $fx['questions']
        ->pluck('id')
        ->map(fn ($id): int => (int) $id)
        ->reject(fn (int $id): bool => $id === $start['question']['question_id'])
        ->first();

    /*
    | 404 rather than 422: an id that was never served is a question about
    | somebody else's paper, and «that is not in your session» for an id that
    | exists elsewhere is a probe that tells the caller which ids do.
    */
    $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
        'question_id' => $unserved,
        'option_ids' => [adaptiveRightOption($unserved)],
    ])->assertNotFound();
});

it('refuses a session that belongs to another student with a 404, not a 403', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy']);
    $other = adaptiveFixture(['easy', 'easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    Sanctum::actingAs($other['student']);
    $this->asGuest();

    // A 403 would confirm that this uuid names a real session belonging to
    // somebody; the existence of another person's session is not news to give.
    $this->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/end", [])
        ->assertNotFound();
});
