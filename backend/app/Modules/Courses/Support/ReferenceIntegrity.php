<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use App\Modules\Courses\Models\Lesson;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * "The thing this item points at still exists" — asked at every read (FR-045).
 *
 * A reference item holds an id and nothing else; `type` says what the id means
 * (R9). Deleting the exam therefore leaves a row pointing at nothing, and the
 * teacher who deleted it has no reason to think they also had to go and find its
 * position in a tree.
 *
 * **The guard is at the READ, not in a deletion listener**, and that is the
 * whole design. A listener has to be registered, has to fire, and has to cover
 * every path that removes a row — a bulk `delete()` retrieves no models and
 * fires no events at all. A condition in the scope is passed through by every
 * reader by construction: the only way to skip it is to write a new query that
 * does not use the scope, which is visible in review.
 *
 * Table names rather than models, on purpose. `exams` lives in Assessments and
 * `class_sessions` in LiveSessions; naming their models here would import a
 * global scope and a module's class into a query that only needs to know
 * whether a row is there.
 *
 * Applied to the three student-facing readers and to none of the authoring ones:
 * a teacher must keep seeing the orphan, because they are the one who has to
 * repoint or delete it.
 */
final class ReferenceIntegrity
{
    /** type → the table its `reference_id` points into. */
    private const TARGETS = [
        'exam' => 'exams',
        'live_session' => 'class_sessions',
    ];

    /**
     * Extra conditions the target row must satisfy to count as present.
     *
     * An exam that has been pulled back to draft is, from the student's side, the
     * same absence as an exam that has been deleted: `AttemptController::start`
     * refuses them 422 and `ExamPolicy::view` refuses them 403, so there is
     * nothing at the end of the item. Treating it as missing rather than as
     * present-but-unusable is what stops it becoming a permanent lock — in a
     * sequential course the gate would otherwise hold everything after it shut,
     * while telling the student to go and sit an exam they cannot open, and cap
     * their percentage below 100 for good.
     *
     * A session has no equivalent: a cancelled one still happened as a fact in
     * the timetable, and `ReferenceSummary` gives it a state to say so.
     *
     * @var array<string, array<string, string>>
     */
    private const REQUIRED = [
        'exams' => ['status' => 'published'],
    ];

    /**
     * Drops items whose target is gone — and items of a reference type that
     * never got one, which is the same thing seen a moment earlier.
     *
     * @template TBuilder of Builder|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function apply(Builder|QueryBuilder $query): Builder|QueryBuilder
    {
        $query->where(function (Builder|QueryBuilder $outer): void {
            // Everything that points at nothing by nature: a video, an article,
            // a link. Untouched.
            $outer->whereNotIn('lessons.type', array_keys(self::TARGETS));

            foreach (self::TARGETS as $type => $table) {
                $outer->orWhere(function (Builder|QueryBuilder $branch) use ($type, $table): void {
                    $branch->where('lessons.type', $type)
                        ->whereExists(function (QueryBuilder $exists) use ($table): void {
                            $exists->select(1)
                                ->from($table)
                                ->whereColumn($table.'.id', 'lessons.reference_id');

                            foreach (self::REQUIRED[$table] ?? [] as $column => $value) {
                                $exists->where($table.'.'.$column, $value);
                            }
                        });
                });
            }
        });

        return $query;
    }

    /**
     * Which of these items point at something that is gone.
     *
     * The teacher's tree does NOT hide an orphan — they are the only person who
     * can repoint or remove it, and a row that silently vanishes from their
     * outline while a student's percentage quietly changes is the worst of both.
     * So it is marked instead, and this says which ones to mark.
     *
     * Two queries for a whole tree, run once from the top-level resource rather
     * than once per row: a lookup inside `lesson()` would be an N+1 by
     * construction, which is the defect `QueryBudgetTest` exists over.
     *
     * @param  iterable<Lesson>  $lessons
     * @return array<int, true> keyed by lesson id — membership, not a list to scan
     */
    public static function missingAmong(iterable $lessons): array
    {
        /** @var array<string, list<Lesson>> $byType */
        $byType = [];

        foreach ($lessons as $lesson) {
            if (array_key_exists($lesson->type, self::TARGETS)) {
                $byType[$lesson->type][] = $lesson;
            }
        }

        $missing = [];

        foreach ($byType as $type => $items) {
            $ids = array_values(array_filter(array_map(
                static fn (Lesson $lesson): ?int => $lesson->reference_id,
                $items,
            )));

            $table = self::TARGETS[$type];

            $live = $ids === []
                ? []
                : DB::table($table)
                    ->whereIn('id', $ids)
                    // The same extra conditions `apply()` uses, so the teacher's
                    // "broken" marker and the student's filter agree about what
                    // missing means. Two definitions here would show the author a
                    // healthy item their students cannot see.
                    ->where(function (QueryBuilder $query) use ($table): void {
                        foreach (self::REQUIRED[$table] ?? [] as $column => $value) {
                            $query->where($column, $value);
                        }
                    })
                    ->pluck('id')->all();

            foreach ($items as $lesson) {
                if ($lesson->reference_id === null || ! in_array($lesson->reference_id, $live, true)) {
                    $missing[(int) $lesson->getKey()] = true;
                }
            }
        }

        return $missing;
    }
}
