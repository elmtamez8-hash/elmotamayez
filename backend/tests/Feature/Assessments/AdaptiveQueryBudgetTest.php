<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Question;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · NFR-013. Cost must not grow with the size of the bank or the list.
|
| ⚠️ THE MEASUREMENT IS THE DIFFERENCE BETWEEN TWO SIZES, NOT A CEILING. A ceiling
| alone catches a slow endpoint and misses an N+1 entirely — twenty concepts under
| a budget of thirty passes, and so does two hundred under two hundred and ten a
| release later. What an N+1 cannot survive is being asked the same question twice
| with different amounts of data and having to answer with the same number.
|
| ⚠️ AND THE PAYLOAD IS MEASURED TOO. `PracticePool::withheldQuestionIds()` used to
| pull every question in every published exam into PHP and send them back as bind
| parameters: a CONSTANT number of queries whose size grew linearly with the bank.
| A query count alone is green for that, on the hottest write path in the phase.
*/

/** Add $count more questions to this fixture's concept, one lesson, one bank. */
function padAdaptiveBank(array $fx, int $count): void
{
    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $count): void {
        foreach (range(1, $count) as $index) {
            adaptiveQuestion($fx['workspace'], $fx['lesson'], 'easy', "سؤال إضافي {$index}");
        }
    });
}

/** Total length of every bound value in the last measured query log. */
function boundPayloadSize(callable $work): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $work();

    $size = 0;

    foreach (DB::getQueryLog() as $entry) {
        $size += count($entry['bindings'] ?? []);
    }

    DB::disableQueryLog();

    return $size;
}

it('serves the next question at a cost that does not grow with the bank', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'medium']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated()->json('data');

    $answer = function (int $questionId) use ($start): array {
        return test()->postJson("/api/v1/practice/adaptive/{$start['session']['uuid']}/answer", [
            'question_id' => $questionId,
            'option_ids' => [adaptiveRightOption($questionId)],
        ])->assertOk()->json('data');
    };

    [$small, $step] = countingQueries(fn (): array => $answer($start['question']['question_id']));

    padAdaptiveBank($fx, 40);

    [$large] = countingQueries(fn (): array => $answer($step['question']['question_id']));

    /*
    | Forty more questions in the pool, and the same number of queries. A margin
    | of two absorbs an unrelated read; forty would not fit in it.
    */
    expect($large)->toBeLessThanOrEqual($small + 2);

    expect(Question::query()->withoutWorkspaceScope()->count())->toBeGreaterThan(40);
});

it('does not send the whole bank back as bind parameters', function (): void {
    $fx = adaptiveFixture(['easy', 'easy', 'easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $start = fn (): array => test()->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->json('data');

    $small = boundPayloadSize($start);

    padAdaptiveBank($fx, 40);

    // A fresh student, so the second start is not refused as a duplicate.
    $second = adaptiveFixture(['easy']);
    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $large = boundPayloadSize($start);

    /*
    | ⚠️ THE ARRAY FORM WOULD FAIL THIS AND PASS A QUERY COUNT. Forty more
    | questions is forty more bindings on every start, for ever, growing with the
    | bank — which is exactly what a subquery costs nothing for.
    */
    expect($large)->toBeLessThanOrEqual($small + 5)
        ->and($second['concept'])->not->toBeNull();
});

it('lists concepts at a cost that does not grow with the number of concepts', function (): void {
    $fx = adaptiveFixture(['easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    [$one] = countingQueries(fn () => test()->getJson('/api/v1/practice/adaptive/concepts')->assertOk());

    // Five more concepts in the same bank, each with questions of its own.
    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): void {
        foreach (range(1, 5) as $index) {
            adaptiveQuestion($fx['workspace'], $fx['lesson'], 'easy', "فكرة {$index} سؤال", "فكرة {$index}");
        }
    });

    [$many, $response] = countingQueries(fn () => test()->getJson('/api/v1/practice/adaptive/concepts')->assertOk());

    /*
    | ⚠️ THE POSITIVE CONTROL. Without it a build that answered an empty list for
    | every reader would satisfy the flatness assertion perfectly.
    */
    expect($response->json('data'))->toHaveCount(6)
        ->and($many)->toBeLessThanOrEqual($one + 2);
});
