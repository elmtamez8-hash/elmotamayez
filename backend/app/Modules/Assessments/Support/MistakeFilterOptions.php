<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * What the mistake notebook's filter bar may offer — and nothing else.
 *
 * ⚠️ EVERY OPTION IS DERIVED FROM THE NOTEBOOK'S OWN QUERY, NEVER ASSEMBLED
 * BESIDE IT. This product has already paid for the other way once: spec 009
 * built a leaderboard picker from the nearest list it had — the student's coin
 * purses — while the API authorised on an active enrolment, so the screen
 * offered boards the server answered `403` and hid boards it allowed. The rule
 * that came out of it is that a picker is derived from the AUTHORISER'S OWN
 * PREDICATE. Here that predicate is {@see MistakeNotebook::notebookRows()}, and
 * `MistakeFiltersTest` walks every option this class returns through the real
 * endpoint.
 *
 * ⚠️ AND IT FOLLOWS THE READER'S VIEW, NOT JUST THE STANDING SET. Derived from
 * the standing mistakes alone, the bar disappeared for anybody who had fixed
 * everything — and pressing «الكل» then listed their whole notebook with no way
 * to filter it. Measured on real seeded data, where every demo mistake had been
 * answered correctly since, so the bar was absent from both views at once.
 *
 * Two consequences follow, and both are the point of the class:
 *
 *   · a teacher, course, exam or concept the student has no standing mistake in
 *     is not offered — an option that answers an empty list is a control that
 *     wastes a tap and teaches the reader not to trust the bar;
 *   · a teacher, course or exam belonging to somebody they do not study with
 *     cannot appear, because the base query is already scoped to the workspaces
 *     they hold an enrolment in.
 *
 * ⚠️ AND THE EXAM FACET IS «WHERE YOU LAST GOT IT WRONG», WHICH IS NARROWER THAN
 * THE FILTER IT FEEDS. The filter asks «was this ever answered wrong in that
 * exam»; this offers the exam of the last wrong answer. So every option is
 * guaranteed to return at least one row, and an exam that only holds older
 * mistakes is simply not offered. The safe direction: a picker may under-offer,
 * it may never offer nothing.
 *
 * ⚠️ IT COSTS FOUR GROUPED QUERIES OVER THE NOTEBOOK'S BASE, and that is not
 * free — it is a filter bar, loaded once per visit, and the alternative (one
 * query returning every facet) needs four joins on a query that already groups.
 * If this ever sits behind a poll, it needs caching first.
 */
class MistakeFilterOptions
{
    public function __construct(private readonly MistakeNotebook $notebook) {}

    /**
     * ⚠️ ONE ROW SHAPE FOR ALL FOUR FACETS — `{uuid, label}`. A workspace has a
     * `name`, a course and an exam have a `title`, a concept has a `name`: four
     * key names for one idea is four branches in the screen that renders them,
     * and the fourth is the one somebody forgets.
     *
     * ⚠️ `has_standing` IS ASKED OF THE STANDING SET WHATEVER THE VIEW, because
     * it answers a different question: whether there is anything to build a
     * revision paper FROM. The bar follows what is on screen; the practice
     * button must not, or «الكل» would offer a paper to a student who has fixed
     * everything — a `422` they cannot act on.
     *
     * @param  list<int>  $workspaceIds
     * @return array{teachers: list<array{uuid: string, label: string}>, courses: list<array{uuid: string, label: string}>, exams: list<array{uuid: string, label: string}>, concepts: list<array{uuid: string, label: string}>, has_standing: bool}
     */
    public function for(array $workspaceIds, int $studentId, bool $includeResolved = false): array
    {
        if ($workspaceIds === []) {
            return ['teachers' => [], 'courses' => [], 'exams' => [], 'concepts' => [], 'has_standing' => false];
        }

        return [
            'has_standing' => $this->notebook->notebookRows($workspaceIds, $studentId)->exists(),
            'teachers' => $this->facet($workspaceIds, $studentId, $includeResolved, function (QueryBuilder $standing): QueryBuilder {
                return DB::query()->fromSub($standing, 's')
                    ->join('questions as q', 'q.id', '=', 's.question_id')
                    ->join('workspaces as w', 'w.id', '=', 'q.workspace_id')
                    ->distinct()
                    ->orderBy('w.name')
                    ->select(['w.uuid as uuid', 'w.name as label']);
            }),
            'courses' => $this->facet($workspaceIds, $studentId, $includeResolved, function (QueryBuilder $standing): QueryBuilder {
                // Through the question's LESSON, which is the same road the
                // `course` filter takes. A question tagged to no lesson has no
                // course and is deliberately absent rather than bucketed.
                return DB::query()->fromSub($standing, 's')
                    ->join('questions as q', 'q.id', '=', 's.question_id')
                    ->join('lessons as l', 'l.id', '=', 'q.lesson_id')
                    ->join('courses as c', 'c.id', '=', 'l.course_id')
                    ->distinct()
                    ->orderBy('c.title')
                    ->select(['c.uuid as uuid', 'c.title as label']);
            }),
            'exams' => $this->facet($workspaceIds, $studentId, $includeResolved, function (QueryBuilder $standing): QueryBuilder {
                // The paper the LAST wrong answer was given in. A practice run
                // carries no exam, so `join` (not `leftJoin`) is what keeps
                // «تدريب» out of a list of exam names.
                return DB::query()->fromSub($standing, 's')
                    ->join('exam_answers as a', 'a.id', '=', 's.last_wrong_id')
                    ->join('exam_attempts as at', 'at.id', '=', 'a.attempt_id')
                    ->join('exams as e', 'e.id', '=', 'at.exam_id')
                    ->distinct()
                    ->orderBy('e.title')
                    ->select(['e.uuid as uuid', 'e.title as label']);
            }),
            'concepts' => $this->facet($workspaceIds, $studentId, $includeResolved, function (QueryBuilder $standing): QueryBuilder {
                return DB::query()->fromSub($standing, 's')
                    ->join('questions as q', 'q.id', '=', 's.question_id')
                    ->join('concepts as cp', 'cp.id', '=', 'q.concept_id')
                    ->distinct()
                    ->orderBy('cp.name')
                    ->select(['cp.uuid as uuid', 'cp.name as label']);
            }),
        ];
    }

    /**
     * @param  list<int>  $workspaceIds
     * @param  callable(QueryBuilder): QueryBuilder  $shape
     * @return list<array{uuid: string, label: string}>
     */
    private function facet(array $workspaceIds, int $studentId, bool $includeResolved, callable $shape): array
    {
        $options = [];

        foreach ($shape($this->notebook->notebookRows($workspaceIds, $studentId, $includeResolved))->get() as $row) {
            $fields = (array) $row;

            $options[] = [
                'uuid' => (string) ($fields['uuid'] ?? ''),
                'label' => (string) ($fields['label'] ?? ''),
            ];
        }

        return $options;
    }
}
