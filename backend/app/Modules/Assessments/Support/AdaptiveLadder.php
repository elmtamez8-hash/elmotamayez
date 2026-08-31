<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Modules\Assessments\Enums\Difficulty;
use App\Modules\Assessments\Models\Question;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which question comes next, and how high this concept can go (FR-002 · FR-004).
 *
 * ⚠️ IT DRAWS FROM `PracticePool` AND NEVER FROM `questions` DIRECTLY. The pool
 * is what an ACTIVE ENROLMENT entitles, minus the questions sitting in an exam
 * this student has not sat — and practising one of those is that exam, with its
 * answers, handed over a week early (FR-006). A second predicate assembled beside
 * the pool is the defect spec 009's leaderboard picker paid for.
 */
class AdaptiveLadder
{
    /**
     * `questions.difficulty` as its ladder rank, for ordering and for grouping.
     *
     * A string column cannot be ordered by meaning, and this is the one place the
     * mapping is written in SQL — `ListAdaptiveConcepts` reads the same constant
     * so the ceiling on the list and the ceiling in a session cannot disagree.
     */
    public const RANK_SQL = "(case questions.difficulty when 'hard' then 3 when 'medium' then 2 else 1 end)";

    public function __construct(private readonly PracticePool $pool) {}

    /**
     * The highest difficulty that has a question available to this student here.
     *
     * ⚠️ NOT `MAX(difficulty)`. The column is a string, and alphabetically
     * `easy < hard < medium` — so `MAX()` answers `medium` for a concept that has
     * hard questions in it, on every engine, silently. The ordering lives in
     * `Difficulty::rank()` and nowhere else.
     *
     * ⚠️ AND IT IS THE WHOLE OF WHY MASTERY IS REACHABLE. Measured literally at
     * `hard`, a concept authored entirely at `easy` could never be mastered: no
     * mastery row, no points, no error. Null here means the concept has nothing
     * this student may practise at all, which the caller refuses with a sentence.
     */
    public function ceilingFor(int $workspaceId, User $student, int $conceptId): ?Difficulty
    {
        $available = $this->conceptPool($workspaceId, $student, $conceptId)
            ->distinct()
            ->pluck('difficulty');

        $ceiling = null;

        foreach ($available as $value) {
            $case = Difficulty::tryFrom((string) $value);

            if ($case !== null && ($ceiling === null || $case->rank() > $ceiling->rank())) {
                $ceiling = $case;
            }
        }

        return $ceiling;
    }

    /**
     * One question at the wanted difficulty, or the nearest one that has any.
     *
     * ⚠️ ONE QUERY, AND THE WALK IS PART OF IT. Asking «is there an easy one?»
     * then «a medium one?» is three round trips on the hottest write path in the
     * phase; ordering by the DISTANCE from what was wanted picks the exact level
     * when it exists and the nearest when it does not, in the same statement.
     *
     * ⚠️ AND A TIE IS BROKEN BY THE DIRECTION OF TRAVEL, NOT BY A CONSTANT. Wanted
     * `medium` with only `easy` and `hard` left is equidistant, and always
     * resolving downward would BRICK the ceiling: a concept with no medium
     * questions would promote to medium, be handed easy, promote again, be handed
     * easy again — for ever, never reaching `hard`, so mastery could never be
     * earned in it. `$direction` is +1 when the student was being promoted and -1
     * when demoted, and it only ever decides a tie: a level that exists at
     * distance one still wins over one at distance two, whichever way it lies.
     *
     * ⚠️ AND THE ALREADY-SERVED SET IS A SUBQUERY OVER `attempt_items`, not a
     * column on the session: the items ARE the record of what was shown, and a
     * second list beside them is the one that drifts.
     */
    public function next(int $workspaceId, User $student, int $conceptId, Difficulty $wanted, int $attemptId, int $direction = 0): ?Question
    {
        return $this->conceptPool($workspaceId, $student, $conceptId)
            ->whereNotIn('id', DB::table('attempt_items')->select('question_id')->where('attempt_id', $attemptId))
            ->with('options')
            ->orderByRaw('abs('.self::RANK_SQL.' - ?) asc', [$wanted->rank()])
            ->orderByRaw(self::RANK_SQL.($direction >= 0 ? ' desc' : ' asc'))
            // Randomised in SQL: taking the first by id hands the same student
            // the same question every session, which is revision of one row.
            ->inRandomOrder()
            ->limit(1)
            ->first();
    }

    /**
     * A question's difficulty as the enum.
     *
     * ⚠️ `questions.difficulty` IS AN UNCAST STRING COLUMN, unlike its neighbour
     * `bloom_level` — and casting it would change what `BankQuestionResource`
     * sends and what two existing tests assert, so the conversion lives here
     * instead. `tryFrom` and not `from`: a value the importer let through would
     * otherwise 500 the session rather than being asked at the easiest level.
     */
    public static function difficultyOf(Question $question): Difficulty
    {
        return Difficulty::tryFrom((string) $question->difficulty) ?? Difficulty::Easy;
    }

    /**
     * @return Builder<Question>
     */
    private function conceptPool(int $workspaceId, User $student, int $conceptId): Builder
    {
        return $this->pool->questionsFor($workspaceId, $student)->where('concept_id', $conceptId);
    }
}
