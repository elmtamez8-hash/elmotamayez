<?php

declare(strict_types=1);

use App\Modules\Assessments\Jobs\RollUpQuestionStatsJob;
use App\Modules\Assessments\Models\ConceptStat;
use App\Modules\Assessments\Models\QuestionStat;
use App\Modules\Courses\Models\Lesson;

/*
| The rollup runs every night, and every night it must find the same rows.
|
| ⚠️ EVERY TEST HERE RUNS THE JOB TWICE, AND THAT IS THE WHOLE POINT. One run is
| green for ever and proves the opposite of what it claims: the defect this
| guards against — a nullable column inside a unique index, which never matches
| itself, so `upsert()` INSERTS instead of updating — is invisible until the
| second night. By the thirtieth the screen is showing a number a month old
| while the correct one sits in another row, with not one error in the log.
*/

it('writes one row per concept however many nights it runs', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $lesson = Lesson::factory()->create(['workspace_id' => $workspace->getKey()]);
    $question = bankQuestion($workspace, null, ['concept_name' => 'التكامل', 'lesson_id' => $lesson->getKey()]);

    sitQuestion($workspace, $question, correct: 4, wrong: 6);

    RollUpQuestionStatsJob::dispatch();
    RollUpQuestionStatsJob::dispatch();

    // The `lesson_id = 0` row specifically: it is the one a NULL sentinel would
    // have duplicated, and it is the row every screen reads.
    expect(ConceptStat::query()
        ->where('concept_id', $question->concept_id)
        ->where('lesson_id', ConceptStat::OVERALL)
        ->count())->toBe(1)
        ->and(ConceptStat::query()->where('concept_id', $question->concept_id)->count())->toBe(2)
        ->and(QuestionStat::query()->where('question_id', $question->getKey())->count())->toBe(1);
});

it('updates the row it already wrote when the answers change', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $question = bankQuestion($workspace);

    sitQuestion($workspace, $question, correct: 5, wrong: 5);
    RollUpQuestionStatsJob::dispatch();

    // Ten more sittings, all of them wrong: 15 wrong out of 20.
    sitQuestion($workspace, $question, correct: 0, wrong: 10);
    RollUpQuestionStatsJob::dispatch();

    $stat = QuestionStat::query()->where('question_id', $question->getKey())->sole();

    expect($stat->attempts_count)->toBe(20)
        ->and($stat->wrong_pct)->toBe(75.0);
});

it('keeps one teacher out of another teacher rollup rows', function (): void {
    [$mine, $me] = $this->createWorkspaceWithOwner();
    [$theirs, $them] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($theirs, $them);
    $hers = bankQuestion($theirs, null, ['concept_name' => 'الهندسة']);
    sitQuestion($theirs, $hers, correct: 0, wrong: 10);

    $this->setCurrentWorkspace($mine, $me);
    $mineQuestion = bankQuestion($mine, null, ['concept_name' => 'الهندسة']);
    sitQuestion($mine, $mineQuestion, correct: 10, wrong: 0);

    RollUpQuestionStatsJob::dispatch();
    RollUpQuestionStatsJob::dispatch();

    // One concept NAME, two teachers, two taxonomies — and the walk visits each
    // workspace on its own, so neither rollup ever sees the other's answers. Two
    // runs, still two rows: the sweep is a rewrite of yesterday, not an append.
    expect(ConceptStat::query()->withoutWorkspaceScope()->count())->toBe(2)
        ->and(QuestionStat::query()->withoutWorkspaceScope()->count())->toBe(2);
});
