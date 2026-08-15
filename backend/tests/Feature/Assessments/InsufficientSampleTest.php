<?php

declare(strict_types=1);

use App\Modules\Assessments\Jobs\RollUpQuestionStatsJob;
use App\Modules\Assessments\Models\QuestionStat;
use App\Modules\Tenancy\Support\PlatformSettings;
use Laravel\Sanctum\Sanctum;

/*
| SC-006 · FR-013. "Nobody got this wrong" and "we do not know yet" are two
| different sentences, and only one of them is safe to show.
|
| ⚠️ EVERY ASSERTION HERE IS `toBeNull()`, NEVER `not->toBe(0)`. A rate that
| arrived as `0` would satisfy the second form on a strict comparison and fail
| the requirement completely: the teacher reading it deletes a good question two
| students happened to sit.
*/

it('states no rate for a question below the sample floor', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $question = bankQuestion($workspace);

    // Two sittings against a floor of five.
    sitQuestion($workspace, $question, correct: 1, wrong: 1);

    RollUpQuestionStatsJob::dispatch();

    $stat = QuestionStat::query()->where('question_id', $question->getKey())->sole();

    // The counts are still written — they are what tells the screen how far the
    // question is from being answerable. Only the RATE is withheld.
    expect($stat->attempts_count)->toBe(2)
        ->and($stat->wrong_count)->toBe(1)
        ->and($stat->wrong_pct)->toBeNull();
});

it('says so on the wire instead of sending a zero', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    sitQuestion($workspace, bankQuestion($workspace), correct: 1, wrong: 1);

    RollUpQuestionStatsJob::dispatch();

    Sanctum::actingAs($owner);

    $response = $this->getJson('/api/v1/manage/analytics/questions');

    $response->assertOk();

    expect($response->json('data.0.wrong_pct'))->toBeNull()
        ->and($response->json('data.0.has_enough_data'))->toBeFalse();
});

it('follows the floor an operator moved rather than a number in the code', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    // A threshold that only changes with a release is a threshold nobody tunes.
    PlatformSettings::set('assessments.min_sample_size', 3);

    $question = bankQuestion($workspace);
    sitQuestion($workspace, $question, correct: 2, wrong: 2);

    RollUpQuestionStatsJob::dispatch();

    expect(QuestionStat::query()->where('question_id', $question->getKey())->sole()->wrong_pct)->toBe(50.0);
});

it('sorts the questions nobody has sat below the ones that need attention', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $unknown = bankQuestion($workspace, null, ['content' => 'حلّه اثنان؟']);
    $worst = bankQuestion($workspace, null, ['content' => 'أخطأ فيه الأغلبية؟']);

    sitQuestion($workspace, $unknown, correct: 1, wrong: 1);
    sitQuestion($workspace, $worst, correct: 2, wrong: 8);

    RollUpQuestionStatsJob::dispatch();

    Sanctum::actingAs($owner);

    // A null sorts before every number on SQLite and after every number on
    // MySQL. Left to the database, the top of this list would be the questions
    // nothing is known about — on exactly one of the two engines.
    expect(collect($this->getJson('/api/v1/manage/analytics/questions')->json('data'))
        ->pluck('question.content')->all())
        ->toBe(['أخطأ فيه الأغلبية؟', 'حلّه اثنان؟']);
});
