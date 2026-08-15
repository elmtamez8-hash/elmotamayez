<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Models\Answer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Everything one student got wrong with one teacher (FR-016).
 *
 * ⚠️ THERE IS NO `mistake_entries` TABLE, AND "FIXED" IS A QUESTION RATHER THAN
 * A COLUMN. A stored status drifts at the first manual regrade and needs a sweep
 * to repair — and the sweep needs a sweep watching it. Derived, the notebook is
 * correct on every row already in the database, with no backfill.
 *
 * ⚠️ ONE GROUPED QUERY PER PAGE, NOT ONE PER MISTAKE. The derivation grows with
 * the student's whole HISTORY, not with the length of the page, so a per-row
 * "was this later answered correctly?" is an N+1 that gets slower the longer
 * somebody studies here (NFR-010).
 *
 * ⚠️ AND THE WORKSPACE IS PASSED IN, NEVER READ FROM THE CONTEXT. `WorkspaceScope`
 * adds no condition at all when the context resolves to null — which it does for
 * any student who belongs to no workspace — so leaning on the global scope here
 * would merge every teacher's questions into one notebook. That breaks FR-016أ in
 * both directions at once: the student sees half their mistakes called all of
 * them, and one teacher's question is read inside another teacher's context.
 */
class MistakeNotebook
{
    /**
     * @param  array{concept?: string, lesson?: string, from?: string, to?: string, include_resolved?: bool}  $filters
     * @return LengthAwarePaginator<int, Answer>
     */
    public function paginate(int $workspaceId, int $studentId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $page = $this->rows($workspaceId, $studentId, $filters)->paginate($perPage);

        // The grouped rows arrive as stdClass; read through an array cast so the
        // shape is stated once rather than assumed at four call sites.
        $rows = array_map(static fn (mixed $row): array => (array) $row, $page->items());

        $answerIds = [];

        foreach ($rows as $row) {
            $answerIds[] = (int) $row['last_wrong_id'];
        }

        $answers = Answer::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $answerIds)
            ->with(['question.concept', 'question.lesson', 'question.options'])
            ->get()
            ->keyBy(fn (Answer $answer): int => (int) $answer->getKey());

        // The list rebuilt in the grouped query's order, with the derived flags
        // carried on the model rather than fetched again per row.
        $items = [];

        foreach ($rows as $row) {
            $answer = $answers->get((int) $row['last_wrong_id']);

            if ($answer === null) {
                continue;
            }

            $answer->setAttribute('is_resolved', $row['last_correct_id'] !== null
                && (int) $row['last_correct_id'] > (int) $row['last_wrong_id']);
            $answer->setAttribute('times_wrong', (int) $row['times_wrong']);

            $items[] = $answer;
        }

        return $page->setCollection(collect($items));
    }

    /**
     * The question ids this student still gets wrong, most recent first.
     *
     * The build action's input. It asks the same grouped query rather than a
     * second definition of "still wrong" — two definitions of one rule are two
     * answers the moment either changes.
     *
     * @param  array{concept?: string, lesson?: string, from?: string, to?: string}  $filters
     * @return list<int>
     */
    public function standingQuestionIds(int $workspaceId, int $studentId, array $filters = [], int $limit = 100): array
    {
        $ids = [];

        foreach ($this->rows($workspaceId, $studentId, $filters)->limit($limit)->get() as $row) {
            $ids[] = (int) ((array) $row)['question_id'];
        }

        return $ids;
    }

    /**
     * One `GROUP BY question_id` over this student's answers with this teacher.
     *
     * @param  array{concept?: string, lesson?: string, from?: string, to?: string, include_resolved?: bool}  $filters
     */
    private function rows(int $workspaceId, int $studentId, array $filters): QueryBuilder
    {
        $query = DB::table('exam_answers as a')
            ->join('questions as q', 'q.id', '=', 'a.question_id')
            ->where('a.workspace_id', $workspaceId)
            ->where('a.student_user_id', $studentId)
            /*
            | ⚠️ AN UNGRADED ESSAY IS NOT A KNOWN MISTAKE. `GradeAttempt` writes
            | every essay row `is_correct = false` because no machine can judge
            | one, so without this the notebook fills with essays nobody has read
            | and tells the student they got them wrong.
            |
            | ⚠️ AND THERE IS DELIBERATELY NO `is_practice` FILTER HERE — the
            | opposite of the US2 rollup, on purpose. Answering correctly IN a
            | practice run is exactly how FR-019 says a mistake gets fixed; filter
            | practice out and the "test me on my mistakes" loop can never
            | resolve anything and hands back the same questions for ever.
            */
            ->where(function (QueryBuilder $sub): void {
                $sub->where('a.requires_grading', false)->orWhereNotNull('a.graded_at');
            })
            ->groupBy('a.question_id')
            ->select(
                'a.question_id',
                DB::raw('COUNT(CASE WHEN a.is_correct = 0 THEN 1 END) as times_wrong'),
                DB::raw('MAX(CASE WHEN a.is_correct = 0 THEN a.id END) as last_wrong_id'),
                // ⚠️ LATER, not merely present. A student who answered correctly
                // once and wrongly afterwards has NOT fixed anything, and
                // `MAX(is_correct)` — the obvious form — reports that they have.
                // The ids are insert-ordered, so the comparison is chronological.
                DB::raw('MAX(CASE WHEN a.is_correct = 1 THEN a.id END) as last_correct_id'),
                DB::raw('MAX(CASE WHEN a.is_correct = 0 THEN a.created_at END) as last_wrong_at'),
            )
            ->havingRaw('MAX(CASE WHEN a.is_correct = 0 THEN a.id END) IS NOT NULL');

        if (($filters['include_resolved'] ?? false) !== true) {
            $query->havingRaw('MAX(CASE WHEN a.is_correct = 1 THEN a.id END) IS NULL OR MAX(CASE WHEN a.is_correct = 1 THEN a.id END) < MAX(CASE WHEN a.is_correct = 0 THEN a.id END)');
        }

        if (($filters['concept'] ?? '') !== '') {
            $query->where('q.concept_id', $this->idOf('concepts', (string) $filters['concept'], $workspaceId));
        }

        if (($filters['lesson'] ?? '') !== '') {
            $query->where('q.lesson_id', $this->idOf('lessons', (string) $filters['lesson'], $workspaceId));
        }

        /*
        | ⚠️ THE PERIOD FILTERS THE MISTAKE, NOT THE ANSWER SET (FR-017). Put as a
        | WHERE, the window hides the later correct answer that lies outside it —
        | so a question missed in January and fixed in March reads as still
        | standing whenever the student filters January.
        */
        if (($filters['from'] ?? '') !== '') {
            $query->havingRaw('MAX(CASE WHEN a.is_correct = 0 THEN a.created_at END) >= ?', [$filters['from']]);
        }

        if (($filters['to'] ?? '') !== '') {
            // The upper bound is the START of the next day: `created_at` is a
            // timestamp and the bound is a date, so `<= to` silently drops
            // everything after midnight on the last day.
            $query->havingRaw('MAX(CASE WHEN a.is_correct = 0 THEN a.created_at END) < ?', [$filters['to'].' 23:59:59']);
        }

        return $query->orderByDesc('last_wrong_id');
    }

    /**
     * A uuid to a local id, inside this teacher's workspace and nowhere else.
     *
     * Zero when it does not resolve rather than null: null in a `where` matches
     * nothing on some engines and everything on a careless rewrite, and a filter
     * naming another teacher's concept must return an empty notebook, not the
     * unfiltered one.
     */
    private function idOf(string $table, string $uuid, int $workspaceId): int
    {
        return (int) DB::table($table)
            ->where('uuid', $uuid)
            ->where('workspace_id', $workspaceId)
            ->value('id');
    }
}
