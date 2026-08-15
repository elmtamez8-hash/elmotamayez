<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Jobs;

use App\Modules\Assessments\Models\ConceptStat;
use App\Modules\Assessments\Models\QuestionStat;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Support\WorkspaceContext;
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
 */
class RollUpQuestionStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How many rollup rows are held before they are written. */
    private const BATCH = 500;

    public function handle(WorkspaceContext $context): void
    {
        $minSample = max(1, (int) PlatformSettings::get('assessments.min_sample_size', 5));
        $computedAt = now();

        /*
        | ⚠️ chunkById, NEVER chunk. `chunk()` paginates by OFFSET, and a
        | workspace created while this walks shifts every later page by one —
        | silently skipping a teacher's whole analysis for that night. Same
        | reason the uuid backfill in 016 walks by id.
        */
        Workspace::query()->orderBy('id')->chunkById(50, function ($workspaces) use ($context, $minSample, $computedAt): void {
            foreach ($workspaces as $workspace) {
                // forWorkspace, never set(): the context is an application-wide
                // singleton that caches its resolution, so a set() here leaks
                // this teacher into whatever the same worker handles next.
                $context->forWorkspace($workspace, function () use ($workspace, $minSample, $computedAt): void {
                    $this->rollUpWorkspace((int) $workspace->getKey(), $minSample, $computedAt);
                });
            }
        });
    }

    private function rollUpWorkspace(int $workspaceId, int $minSample, Carbon $computedAt): void
    {
        $this->rollUpQuestions($workspaceId, $minSample, $computedAt);
        $this->rollUpConcepts($workspaceId, $minSample, $computedAt);
    }

    private function rollUpQuestions(int $workspaceId, int $minSample, Carbon $computedAt): void
    {
        $rows = [];

        $query = $this->countable($workspaceId)
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

    private function rollUpConcepts(int $workspaceId, int $minSample, Carbon $computedAt): void
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
