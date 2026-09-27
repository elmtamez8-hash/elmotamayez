<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Jobs;

use App\Modules\Assessments\Models\ConceptStat;
use App\Modules\Assessments\Models\QuestionStat;
use App\Modules\Assessments\Support\QuestionStatRollupState;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\RunsAlone;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The nightly rollup behind every item-analysis screen (FR-011 · FR-014).
 *
 * ⚠️ IT EXISTS SO THE SCREEN DOES NOT DO THIS. Three GROUP BYs over the two
 * fastest-growing tables in the product, on every page open, is precisely what
 * SC-013 forbids — so the reader touches `question_stats` and `concept_stats`
 * and nothing else.
 *
 * ⚠️ PRACTICE ATTEMPTS ARE EXCLUDED, AND THE JOIN IS THERE ONLY FOR THAT.
 * `is_practice` sits on `exam_attempts` while the answers sit on `exam_answers`,
 * so without the join these rates mix a student revising alone into a number the
 * teacher uses to decide whether a question is broken.
 *
 * ⚠️ AND AN UNGRADED ESSAY IS NOT A WRONG ANSWER. `GradeAttempt` writes every
 * essay row with `is_correct = false` because no machine can say otherwise yet;
 * counting those would report 100% wrong for every essay in the bank, and
 * permanently for any nobody has marked. They enter the rollup the night after a
 * person grades them, with no change here.
 *
 * ⚠️ INCREMENTAL, AND EVERY ROW IT WRITES IS THE ROW A FULL RUN WOULD WRITE.
 * A run recomputes only the questions with an answer written since the previous
 * run started ({@see QuestionStatRollupState}), and only the concepts those
 * questions belong to — each recomputed from ALL of its answers, never by adding
 * a delta to yesterday's count, so there is no drift to accumulate. Three things
 * leave no `updated_at` on an answer and so force wider work:
 *
 *  - a DELETED answer (retention sweep, erasure request) — the deleter asks for a
 *    full run ({@see QuestionStatRollupState::requestFullRecompute()});
 *  - a moved `min_sample_size` — `wrong_pct` is derived from it, so every row
 *    changes; the run that sees a different floor is a full one;
 *  - an EDITED question — its concept or lesson may have moved, and the concept
 *    it LEFT is recorded nowhere. Any question edited since the last run makes
 *    that workspace's concept rows a full recompute (its question rows stay
 *    incremental: they do not depend on the question's tags).
 *
 * `RollupIncrementalEqualsFullTest` is the guard: an incremental run followed by
 * a forced full one must change nothing but `computed_at`.
 */
class RollUpQuestionStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsAlone, SerializesModels;

    /** How many rollup rows are held before they are written. */
    private const BATCH = 500;

    /**
     * How far before the previous run's start an answer still counts as new.
     *
     * An answer is stamped when its statement runs but seen only once its
     * transaction commits, so one written just before the previous run started
     * may have been invisible to it. Recomputing a question twice is harmless —
     * each run rewrites the whole row — so the overlap is generous.
     */
    private const OVERLAP_MINUTES = 60;

    public function handle(WorkspaceContext $context): void
    {
        $minSample = max(1, (int) PlatformSettings::get('assessments.min_sample_size', 5));
        // Captured BEFORE the first read: it becomes the next run's watermark, so
        // anything written while this one walks is newer than it.
        $computedAt = now();

        // null = recompute everything (first run, a moved floor, a deletion).
        $since = QuestionStatRollupState::incrementalSince($minSample)?->copy()->subMinutes(self::OVERLAP_MINUTES);

        /*
        | ⚠️ chunkById, NEVER chunk. `chunk()` paginates by OFFSET, and a
        | workspace created while this walks shifts every later page by one —
        | silently skipping a teacher's whole analysis for that night. Same
        | reason the uuid backfill in 016 walks by id.
        */
        Workspace::query()->orderBy('id')->chunkById(50, function ($workspaces) use ($context, $minSample, $computedAt, $since): void {
            foreach ($workspaces as $workspace) {
                // forWorkspace, never set(): the context is an application-wide
                // singleton that caches its resolution, so a set() here leaks
                // this teacher into whatever the same worker handles next.
                $context->forWorkspace($workspace, function () use ($workspace, $minSample, $computedAt, $since): void {
                    $this->rollUpWorkspace((int) $workspace->getKey(), $minSample, $computedAt, $since);
                });
            }
        });

        // Only after every workspace is written: a run that dies halfway leaves
        // the old watermark, and the next one simply covers the same ground.
        QuestionStatRollupState::recordRun($computedAt, $minSample, $since === null);
    }

    private function rollUpWorkspace(int $workspaceId, int $minSample, Carbon $computedAt, ?CarbonInterface $since): void
    {
        if ($since === null) {
            $this->rollUpQuestions($workspaceId, $minSample, $computedAt, null);
            $this->rollUpConcepts($workspaceId, $minSample, $computedAt, null);

            return;
        }

        $answersChanged = $this->changedQuestionIds($workspaceId, $since)->exists();

        $questionsEdited = DB::table('questions')
            ->where('workspace_id', $workspaceId)
            ->where('updated_at', '>=', $since)
            ->exists();

        if (! $answersChanged && ! $questionsEdited) {
            return;
        }

        if ($answersChanged) {
            $this->rollUpQuestions($workspaceId, $minSample, $computedAt, $this->changedQuestionIds($workspaceId, $since));
        }

        // An edited question may have LEFT a concept, and nothing records which:
        // this workspace's concept rows are recomputed whole. Otherwise only the
        // concepts that the changed answers' questions belong to.
        $this->rollUpConcepts(
            $workspaceId,
            $minSample,
            $computedAt,
            $questionsEdited
                ? null
                : DB::table('questions')->whereIn('id', $this->changedQuestionIds($workspaceId, $since))->select('concept_id'),
        );
    }

    /**
     * The questions with an answer written (inserted or graded) since `$since`.
     * Served by `exam_answers_workspace_updated_index`.
     */
    private function changedQuestionIds(int $workspaceId, CarbonInterface $since): QueryBuilder
    {
        return DB::table('exam_answers')
            ->where('workspace_id', $workspaceId)
            ->where('updated_at', '>=', $since)
            ->select('question_id');
    }

    /**
     * @param  QueryBuilder|null  $onlyQuestions  a subquery of question ids; null = every question
     */
    private function rollUpQuestions(int $workspaceId, int $minSample, Carbon $computedAt, ?QueryBuilder $onlyQuestions): void
    {
        $rows = [];

        $query = $this->countable($workspaceId);

        if ($onlyQuestions !== null) {
            $query->whereIn('a.question_id', $onlyQuestions);
        }

        $query = $query
            ->groupBy('a.question_id')
            ->select('a.question_id', ...$this->counters());

        foreach ($query->cursor() as $row) {
            $rows[] = [
                'workspace_id' => $workspaceId,
                'question_id' => (int) $row->question_id,
                ...$this->rates((int) $row->attempts, (int) $row->wrong, $minSample),
                'computed_at' => $computedAt,
            ];

            if (count($rows) >= self::BATCH) {
                $this->writeQuestions($rows);
                $rows = [];
            }
        }

        $this->writeQuestions($rows);
    }

    /**
     * @param  QueryBuilder|null  $onlyConcepts  a subquery of concept ids; null = every concept
     */
    private function rollUpConcepts(int $workspaceId, int $minSample, Carbon $computedAt, ?QueryBuilder $onlyConcepts): void
    {
        /*
        | Two passes, and they must stay two.
        |
        | The overall row groups on the concept ALONE, so a question tagged with
        | no lesson still counts toward its concept. The per-lesson rows then
        | skip those same untagged questions — `COALESCE(lesson_id, 0)` would
        | send them to key zero, which is the overall row's key, and the two
        | upserts would overwrite each other every night.
        */
        $overall = $this->countable($workspaceId)
            ->join('questions as q', 'q.id', '=', 'a.question_id')
            ->groupBy('q.concept_id')
            ->select('q.concept_id', DB::raw(ConceptStat::OVERALL.' as lesson_id'), ...$this->counters());

        $perLesson = $this->countable($workspaceId)
            ->join('questions as q', 'q.id', '=', 'a.question_id')
            ->whereNotNull('q.lesson_id')
            ->groupBy('q.concept_id', 'q.lesson_id')
            ->select('q.concept_id', 'q.lesson_id', ...$this->counters());

        if ($onlyConcepts !== null) {
            $overall->whereIn('q.concept_id', $onlyConcepts);
            $perLesson->whereIn('q.concept_id', $onlyConcepts);
        }

        $rows = [];

        foreach ([$overall, $perLesson] as $query) {
            foreach ($query->cursor() as $row) {
                $rows[] = [
                    'workspace_id' => $workspaceId,
                    'concept_id' => (int) $row->concept_id,
                    'lesson_id' => (int) $row->lesson_id,
                    ...$this->rates((int) $row->attempts, (int) $row->wrong, $minSample),
                    'computed_at' => $computedAt,
                ];

                if (count($rows) >= self::BATCH) {
                    $this->writeConcepts($rows);
                    $rows = [];
                }
            }
        }

        $this->writeConcepts($rows);
    }

    /**
     * The answers that count: this workspace, not practice, and actually judged.
     */
    private function countable(int $workspaceId): QueryBuilder
    {
        return DB::table('exam_answers as a')
            ->join('exam_attempts as t', 't.id', '=', 'a.attempt_id')
            ->where('a.workspace_id', $workspaceId)
            ->where('t.is_practice', false)
            ->where(function (QueryBuilder $query): void {
                $query->where('a.requires_grading', false)
                    ->orWhereNotNull('a.graded_at');
            });
    }

    /**
     * @return array<int, Expression>
     */
    private function counters(): array
    {
        return [
            DB::raw('COUNT(*) as attempts'),
            // CASE rather than `SUM(!is_correct)`: the negation operator is
            // MySQL's and SQLite does not have it, so the portable form is the
            // only one both the suite and production can run.
            DB::raw('SUM(CASE WHEN a.is_correct = 0 THEN 1 ELSE 0 END) as wrong'),
        ];
    }

    /**
     * ⚠️ Below the floor the rate is NULL, never zero (FR-013).
     *
     * The counts are still written — "two people sat this" is useful, and it is
     * what tells the screen how far the question is from being answerable.
     *
     * @return array{attempts_count: int, wrong_count: int, wrong_pct: float|null}
     */
    private function rates(int $attempts, int $wrong, int $minSample): array
    {
        return [
            'attempts_count' => $attempts,
            'wrong_count' => $wrong,
            'wrong_pct' => $attempts >= $minSample ? round($wrong / $attempts * 100, 2) : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeQuestions(array $rows): void
    {
        if ($rows !== []) {
            // The unique index is what makes the second night a no-op instead of
            // a second row. Nothing here checks first.
            QuestionStat::query()->upsert($rows, ['question_id'], ['workspace_id', 'attempts_count', 'wrong_count', 'wrong_pct', 'computed_at']);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function writeConcepts(array $rows): void
    {
        if ($rows !== []) {
            ConceptStat::query()->upsert($rows, ['workspace_id', 'concept_id', 'lesson_id'], ['attempts_count', 'wrong_count', 'wrong_pct', 'computed_at']);
        }
    }
}
