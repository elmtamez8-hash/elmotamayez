<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Models\Answer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Everything one student got wrong, with the teachers they study with (FR-016).
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
 * ⚠️ AND THE WORKSPACES ARE PASSED IN, NEVER READ FROM THE CONTEXT. `WorkspaceScope`
 * adds no condition at all when the context resolves to null — which it does for
 * any student who belongs to no workspace — so leaning on the global scope here
 * would merge every teacher's questions into one notebook, silently and without
 * saying whose is whose.
 *
 * ⚠️ IT IS A **LIST** NOW, AND FR-016أ IS KEPT BY THE ROW RATHER THAN BY THE
 * QUERY. The requirement asked for a notebook per teacher, and the endpoint
 * enforced it by refusing to answer at all without a workspace context — which
 * a real student never has, so `GET /mistakes` answered every one of them
 * `422` and the notebook did not exist for anybody it was written for. What the
 * requirement is actually protecting is stated in its own words: that the
 * student not be shown half their mistakes called all of them, and that one
 * teacher's question not be read inside another's. Both hold with a list, so
 * long as **every row names its teacher** — which is why `stampTeachers()` is
 * not decoration and why `MistakeResource` sends it.
 *
 * @phpstan-type MistakeFilters array{teacher?: string, course?: string, exam?: string, concept?: string, lesson?: string, from?: string, to?: string, include_resolved?: bool}
 */
class MistakeNotebook
{
    /**
     * @param  list<int>  $workspaceIds
     * @param  MistakeFilters  $filters
     * @return LengthAwarePaginator<int, Answer>
     */
    public function paginate(array $workspaceIds, int $studentId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $page = $this->rows($workspaceIds, $studentId, $filters)->paginate($perPage);

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

        $this->stampTeachers($items);

        return $page->setCollection(collect($items));
    }

    /**
     * The unfiltered set the reader is looking at, for the bar to derive its
     * options from.
     *
     * ⚠️ EXPOSED SO {@see MistakeFilterOptions} CANNOT SPELL «STILL WRONG» A
     * SECOND TIME. A picker built beside the reader rather than from it offers
     * options the reader returns nothing for, and hides ones it would have
     * answered — spec 009's leaderboard picker, exactly.
     *
     * ⚠️ AND IT TAKES `$includeResolved`, WHICH IS NOT A NARROWING BUT A VIEW.
     * Derived from the STANDING set alone, the bar vanished the moment a student
     * had fixed everything — and «الكل» then listed eight questions with no way
     * to filter them, which is the same picker/reader mismatch wearing its other
     * face. Found on real seeded data, where every demo mistake happens to have
     * been answered correctly afterwards, so the notebook's default view is
     * legitimately empty and the bar was legitimately absent from BOTH views.
     *
     * @param  list<int>  $workspaceIds
     */
    public function notebookRows(array $workspaceIds, int $studentId, bool $includeResolved = false): QueryBuilder
    {
        return $this->rows($workspaceIds, $studentId, ['include_resolved' => $includeResolved]);
    }

    /**
     * The teacher each row belongs to, in ONE query for the page.
     *
     * ⚠️ NOT A RELATION READ PER ROW. `Answer` has no `workspace` relation and
     * adding one would be a query inside a Resource — an N+1 by construction,
     * the `ClassSessionResource` defect this repository has already paid for
     * twice. Carried on the model exactly as `is_resolved` and `times_wrong`
     * are, for the same reason.
     *
     * @param  list<Answer>  $items
     */
    private function stampTeachers(array $items): void
    {
        $ids = [];

        foreach ($items as $answer) {
            $ids[] = (int) $answer->workspace_id;
        }

        if ($ids === []) {
            return;
        }

        $teachers = DB::table('workspaces')
            ->whereIn('id', array_values(array_unique($ids)))
            ->get(['id', 'uuid', 'name'])
            ->keyBy(fn (mixed $row): int => (int) $row->id);

        foreach ($items as $answer) {
            $teacher = $teachers->get((int) $answer->workspace_id);

            $answer->setAttribute('teacher_uuid', $teacher?->uuid);
            $answer->setAttribute('teacher_name', $teacher?->name);
        }
    }

    /**
     * The question ids this student still gets wrong, most recent first.
     *
     * The build action's input. It asks the same grouped query rather than a
     * second definition of "still wrong" — two definitions of one rule are two
     * answers the moment either changes.
     *
     * ⚠️ ONE WORKSPACE, AND DELIBERATELY NOT THE LIST {@see paginate()} TAKES.
     * A revision paper belongs to one teacher — one bank, one withholding rule —
     * so this stays narrow on purpose.
     *
     * @param  MistakeFilters  $filters
     * @return list<int>
     */
    public function standingQuestionIds(int $workspaceId, int $studentId, array $filters = [], int $limit = 100): array
    {
        $ids = [];

        foreach ($this->rows([$workspaceId], $studentId, $filters)->limit($limit)->get() as $row) {
            $ids[] = (int) ((array) $row)['question_id'];
        }

        return $ids;
    }

    /**
     * One `GROUP BY question_id` over this student's answers with these teachers.
     *
     * ⚠️ A LIST OF WORKSPACES, AND AN EMPTY ONE MATCHES NOTHING. `whereIn(…, [])`
     * returns no row, which is the direction a scope has to fail in — a student
     * enrolled nowhere gets an empty notebook, never everybody's.
     *
     * @param  list<int>  $workspaceIds
     * @param  MistakeFilters  $filters
     */
    private function rows(array $workspaceIds, int $studentId, array $filters): QueryBuilder
    {
        $query = DB::table('exam_answers as a')
            ->join('questions as q', 'q.id', '=', 'a.question_id')
            ->whereIn('a.workspace_id', $workspaceIds)
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

        /*
        | ⚠️ THE `OR` IS PARENTHESISED, AND IT WAS NOT.
        |
        | `AND` binds tighter than `OR`, so a bare disjunction here does not mean
        | «still standing» — it splits the whole HAVING into two arms and every
        | condition added AFTER it attaches to the right-hand one alone. It was
        | latent while these were the only two clauses (the second arm implies
        | the first), and the `exam` filter below is what turned it into a filter
        | that did nothing at all: `?exam=` came back with every teacher's
        | mistakes, in an endpoint whose whole job is narrowing.
        |
        | The same un-parenthesised OR that let `?course=` escape
        | `ExamController@index` for anybody holding EXAMS_VIEW. Second file,
        | same defect.
        */
        if (($filters['include_resolved'] ?? false) !== true) {
            $query->havingRaw('(MAX(CASE WHEN a.is_correct = 1 THEN a.id END) IS NULL OR MAX(CASE WHEN a.is_correct = 1 THEN a.id END) < MAX(CASE WHEN a.is_correct = 0 THEN a.id END))');
        }

        /*
        | ⚠️ THE TEACHER NARROWS **WITHIN** WHAT IS ALREADY ALLOWED, and a uuid
        | outside it empties the notebook rather than widening it. `workspaces`
        | carries no `workspace_id` column — its own uuid IS the key — so this
        | one cannot go through `idOf()`.
        */
        if (($filters['teacher'] ?? '') !== '') {
            $query->whereIn('a.workspace_id', array_values(array_intersect(
                $workspaceIds,
                [$this->workspaceIdOf((string) $filters['teacher'])],
            )));
        }

        if (($filters['concept'] ?? '') !== '') {
            $query->where('q.concept_id', $this->idOf('concepts', (string) $filters['concept'], $workspaceIds));
        }

        if (($filters['lesson'] ?? '') !== '') {
            $query->where('q.lesson_id', $this->idOf('lessons', (string) $filters['lesson'], $workspaceIds));
        }

        /*
        | ⚠️ THE COURSE IS A PROPERTY OF THE QUESTION, SO IT IS A `WHERE`.
        |
        | A question sits in one lesson and a lesson in one course, so this
        | condition cannot split a question's answer history — unlike the exam
        | below. The join is added only when the filter is present, so the base
        | query costs exactly what it did before; and it is `join`, not
        | `leftJoin`, deliberately: a question tagged to no lesson has no course
        | and must not come back under one.
        */
        if (($filters['course'] ?? '') !== '') {
            $query->join('lessons as l', 'l.id', '=', 'q.lesson_id')
                ->where('l.course_id', $this->idOf('courses', (string) $filters['course'], $workspaceIds));
        }

        /*
        | ⚠️ THE EXAM IS A PROPERTY OF THE **ANSWER**, SO IT IS A `HAVING` — the
        | same trap the period filters below carry, and the sharper version of
        | it.
        |
        | Written as a `WHERE at.exam_id = ?`, the condition removes every answer
        | given anywhere else — including the later CORRECT one, which for this
        | product is usually a practice run whose attempt has no exam at all. So
        | a mistake made in that exam and fixed afterwards would read as still
        | standing, for ever, and «اختبرني في أخطائي» would keep handing it back.
        | As a HAVING it asks «was this question ever answered wrong in that
        | exam», and the fix outside it still counts as a fix.
        |
        | One answer belongs to one attempt, so the join adds no rows.
        */
        if (($filters['exam'] ?? '') !== '') {
            $query->join('exam_attempts as at', 'at.id', '=', 'a.attempt_id')
                ->havingRaw(
                    'MAX(CASE WHEN a.is_correct = 0 AND at.exam_id = ? THEN a.id END) IS NOT NULL',
                    [$this->idOf('exams', (string) $filters['exam'], $workspaceIds)],
                );
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
    /** @param  list<int>  $workspaceIds */
    private function idOf(string $table, string $uuid, array $workspaceIds): int
    {
        return (int) DB::table($table)
            ->where('uuid', $uuid)
            ->whereIn('workspace_id', $workspaceIds)
            ->value('id');
    }

    /**
     * A workspace uuid to its id — `0` when it does not resolve.
     *
     * Not `idOf()`: `workspaces` has no `workspace_id` column of its own, and
     * the caller intersects the answer with what is already allowed rather than
     * trusting this to scope anything.
     */
    private function workspaceIdOf(string $uuid): int
    {
        return (int) DB::table('workspaces')->where('uuid', $uuid)->value('id');
    }
}
