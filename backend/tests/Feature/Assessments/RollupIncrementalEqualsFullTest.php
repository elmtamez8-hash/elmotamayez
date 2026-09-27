<?php

declare(strict_types=1);

use App\Modules\Assessments\Jobs\RollUpQuestionStatsJob;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Support\AssessmentsPersonalData;
use App\Modules\Assessments\Support\QuestionStatRollupState;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use Illuminate\Support\Facades\DB;

/*
| The rollup is incremental: a run recomputes only the questions whose answers
| changed since the previous run, and the concepts they belong to.
|
| ⚠️ THE GUARD IS EQUALITY WITH A FULL RUN, ROW FOR ROW. An incremental rollup
| that drifted — a concept a moved question left, a regraded essay, an answer the
| retention sweep deleted — would still produce plausible numbers, and nobody
| reading the screen could tell. So each case below runs incrementally, then
| forces a full recompute, and asserts that NOTHING but `computed_at` moved.
*/

/**
 * Every rollup row on the platform, without the two columns a rewrite changes.
 *
 * @return array{questions: list<array<string, mixed>>, concepts: list<array<string, mixed>>}
 */
function rollupEqSnapshot(): array
{
    $questions = DB::table('question_stats')
        ->orderBy('question_id')
        ->get(['workspace_id', 'question_id', 'attempts_count', 'wrong_count', 'wrong_pct'])
        ->map(fn ($row): array => (array) $row)
        ->all();

    $concepts = DB::table('concept_stats')
        ->orderBy('workspace_id')->orderBy('concept_id')->orderBy('lesson_id')
        ->get(['workspace_id', 'concept_id', 'lesson_id', 'attempts_count', 'wrong_count', 'wrong_pct'])
        ->map(fn ($row): array => (array) $row)
        ->all();

    return ['questions' => $questions, 'concepts' => $concepts];
}

function rollupEqComputedAt(Question $question): string
{
    return (string) DB::table('question_stats')->where('question_id', $question->getKey())->value('computed_at');
}

it('writes exactly what a full recompute writes, and leaves untouched questions alone', function (): void {
    [$algebraShop, $alice] = $this->createWorkspaceWithOwner();
    [$calculusShop, $bob] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($calculusShop, $bob);
    $integrals = bankQuestion($calculusShop, null, ['concept_name' => 'التكامل']);
    $untouchedB = bankQuestion($calculusShop, null, ['concept_name' => 'الاحتمالات']);
    sitQuestion($calculusShop, $integrals, correct: 3, wrong: 3);
    // Two essays nobody has marked yet: not counted until a person grades them.
    sitQuestion($calculusShop, $integrals, correct: 0, wrong: 2, answerOverrides: ['requires_grading' => true]);
    sitQuestion($calculusShop, $untouchedB, correct: 4, wrong: 2);

    $this->setCurrentWorkspace($algebraShop, $alice);
    $lesson = Lesson::factory()->create(['workspace_id' => $algebraShop->getKey()]);
    $tagged = bankQuestion($algebraShop, null, ['concept_name' => 'الجبر', 'lesson_id' => $lesson->getKey()]);
    $moving = bankQuestion($algebraShop, null, ['concept_name' => 'الجبر']);
    $geometry = bankQuestion($algebraShop, null, ['concept_name' => 'الهندسة', 'lesson_id' => $lesson->getKey()]);
    $untouchedA = bankQuestion($algebraShop, null, ['concept_name' => 'الهندسة']);
    sitQuestion($algebraShop, $tagged, correct: 5, wrong: 1);
    sitQuestion($algebraShop, $moving, correct: 2, wrong: 4);
    sitQuestion($algebraShop, $geometry, correct: 1, wrong: 5);
    sitQuestion($algebraShop, $untouchedA, correct: 6, wrong: 0);

    // The job re-reads answers written up to an hour before its previous run (a
    // transaction still open then may have been invisible to it), so these must
    // predate night one by more than that, or every question reads as new.
    $this->travel(2)->hours();

    // Night one: no state yet, so a full run.
    RollUpQuestionStatsJob::dispatch();

    $untouchedAStamp = rollupEqComputedAt($untouchedA);
    $untouchedBStamp = rollupEqComputedAt($untouchedB);

    // A day passes — well clear of the job's overlap window.
    $this->travel(1)->days();

    // New answers on one question.
    sitQuestion($algebraShop, $tagged, correct: 0, wrong: 4);
    // A question MOVES concept: the concept it left must lose its answers.
    Question::query()->withoutWorkspaceScope()->whereKey($moving->getKey())
        ->firstOrFail()->forceFill(['concept_id' => $geometry->concept_id])->save();
    // The essays are graded in the other workspace: they enter the rollup now.
    Answer::query()->withoutWorkspaceScope()
        ->where('question_id', $integrals->getKey())
        ->where('requires_grading', true)
        ->get()
        ->each(fn (Answer $answer) => $answer->forceFill(['graded_at' => now(), 'is_correct' => true, 'points' => 1])->save());

    // Night two: incremental.
    RollUpQuestionStatsJob::dispatch();
    $incremental = rollupEqSnapshot();

    // It really was incremental: questions with no new answer were not rewritten.
    expect(rollupEqComputedAt($untouchedA))->toBe($untouchedAStamp)
        ->and(rollupEqComputedAt($untouchedB))->toBe($untouchedBStamp)
        ->and(rollupEqComputedAt($tagged))->not->toBe($untouchedAStamp);

    // And what it wrote is right: the graded essays count, the new answers count.
    expect(DB::table('question_stats')->where('question_id', $integrals->getKey())->value('attempts_count'))->toBe(8)
        ->and(DB::table('question_stats')->where('question_id', $tagged->getKey())->value('attempts_count'))->toBe(10);

    // Night three: forced full. Nothing may change but the timestamps.
    $this->travel(1)->minutes();
    QuestionStatRollupState::requestFullRecompute();
    RollUpQuestionStatsJob::dispatch();

    expect(rollupEqSnapshot())->toEqual($incremental)
        ->and(rollupEqComputedAt($untouchedA))->not->toBe($untouchedAStamp);
});

it('recomputes everything after answers are deleted, because a deletion leaves no timestamp', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $question = bankQuestion($workspace);
    sitQuestion($workspace, $question, correct: 3, wrong: 3);
    sitQuestion($workspace, $question, correct: 0, wrong: 4);

    RollUpQuestionStatsJob::dispatch();

    expect(DB::table('question_stats')->where('question_id', $question->getKey())->value('attempts_count'))->toBe(10);

    $this->travel(1)->days();

    // An erasure request for the student who sat the four wrong answers.
    $erased = Answer::query()->withoutWorkspaceScope()
        ->where('question_id', $question->getKey())
        ->latest('id')
        ->firstOrFail()
        ->student;

    app(AssessmentsPersonalData::class)->erase(new DataSubject($erased), ErasureMode::Delete, 1000);

    RollUpQuestionStatsJob::dispatch();
    $afterIncremental = rollupEqSnapshot();

    expect(DB::table('question_stats')->where('question_id', $question->getKey())->value('attempts_count'))->toBe(6);

    $this->travel(1)->minutes();
    QuestionStatRollupState::requestFullRecompute();
    RollUpQuestionStatsJob::dispatch();

    expect(rollupEqSnapshot())->toEqual($afterIncremental);
});
