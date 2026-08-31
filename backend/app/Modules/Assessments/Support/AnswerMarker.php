<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Events\MistakeResolved;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use DomainException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Marks ONE answer against the snapshot the student was shown.
 *
 * Extracted from {@see GradeAttempt} rather
 * than copied, because spec 012's adaptive path marks question by question while
 * the exam path marks a whole paper at once — and the comment above the old loop
 * warned in its own words about double-counting every mistake in the notebook. A
 * second implementation of "is this right, and does getting it right FIX
 * something" is that warning coming true a release later.
 *
 * ⚠️ IT READS THE SNAPSHOT, NEVER THE LIVE QUESTION. `attempt_items` holds what
 * the student was shown when they started; grading against the current question
 * would re-score a paper the teacher edited afterwards (008 FR-004).
 *
 * ⚠️ AND IT TAKES «WHAT THIS STUDENT ALREADY GOT WRONG» AS AN ARGUMENT, IT DOES
 * NOT DERIVE IT. Deriving it here would put one query per question inside the
 * exam path's loop — the N+1 the extracted comment exists to forbid — and, worse,
 * every question after the first would find a wrong answer it had just written
 * for itself. {@see previouslyWrongQuestionIds()} is asked ONCE, before the loop
 * and before any row is written.
 */
class AnswerMarker
{
    /**
     * Write this student's answer to one question and say whether it was right.
     *
     * ⚠️ `insertOrIgnore` AND THE READ-BACK, NEVER `create()` IN A TRY/CATCH.
     * `unique(attempt_id, question_id)` IS the idempotency guard, and a caught
     * `QueryException` makes a real failure — a null, a foreign key, a value out
     * of range — indistinguishable from a duplicate. The price of the raw insert
     * is that no model is booted: `uuid`, the timestamps and the json cast are
     * supplied here by hand, because `HasUuid` is not there to supply them.
     *
     * Zero rows written means EITHER a duplicate OR a failure MySQL downgraded to
     * a warning, so the row is fetched by its key: found is a duplicate and a
     * clean refusal, missing is a fault and throws.
     *
     * @param  list<int>  $selected  option ids the student chose
     * @param  list<int>  $previouslyWrong  question ids this student has already got wrong
     * @return bool whether the answer was correct
     *
     * @throws DomainException when this question already has an answer in this attempt
     */
    public function mark(
        Attempt $attempt,
        AttemptItem $item,
        array $selected,
        ?string $answerText,
        array $previouslyWrong,
    ): bool {
        $isEssay = $item->requiresGrading();
        $correct = ! $isEssay && $this->matchesSnapshot($item, $selected);
        $now = now();

        $written = Answer::query()->insertOrIgnore([
            'uuid' => (string) Str::orderedUuid(),
            'workspace_id' => $attempt->workspace_id,
            'attempt_id' => $attempt->getKey(),
            'question_id' => $item->question_id,
            // Written once, here, and never updated: this is what keeps the
            // duplicated column from drifting (plan.md §Complexity).
            'student_user_id' => $attempt->student_user_id,
            'selected_option_ids' => json_encode($selected),
            'answer_text' => $answerText,
            'is_correct' => $correct,
            'points' => $correct ? (int) $item->points : 0,
            'requires_grading' => $isEssay,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($written === 0) {
            $this->refuseOrFail($attempt, (int) $item->question_id);
        }

        /*
         | ⚠️ AFTER THE INSERT SUCCEEDED, NEVER BESIDE IT. The event is what
         | closes a mistake in the notebook, and firing it for a write that was
         | ignored as a duplicate closes one twice — the second time against a row
         | somebody else's request already accounted for.
         */
        if ($correct && in_array((int) $item->question_id, $previouslyWrong, true)) {
            event(new MistakeResolved(
                (int) $attempt->student_user_id,
                (int) $item->question_id,
                (int) $attempt->workspace_id,
            ));
        }

        return $correct;
    }

    /**
     * Which of these questions this student has already got wrong before.
     *
     * ONE QUERY, asked before any answer row is written — see the class docblock.
     *
     * @param  array<int, int>  $questionIds
     * @return list<int>
     */
    public function previouslyWrongQuestionIds(Attempt $attempt, array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }

        $rows = Answer::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $attempt->workspace_id)
            ->where('student_user_id', $attempt->student_user_id)
            ->whereIn('question_id', $questionIds)
            ->where('is_correct', false)
            // The same predicate the notebook uses: an essay nobody has read is
            // not a mistake, so answering it correctly later fixes nothing.
            ->where(fn ($query) => $query->where('requires_grading', false)->orWhereNotNull('graded_at'))
            ->distinct()
            ->pluck('question_id');

        $ids = [];

        foreach ($rows as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * Compare against the correct set as it stood when the paper was handed over.
     *
     * @param  list<int>  $selected
     */
    public function matchesSnapshot(AttemptItem $item, array $selected): bool
    {
        $correct = $item->correctOptionIds();
        sort($correct);
        sort($selected);

        return $correct === $selected && $correct !== [];
    }

    /**
     * Nothing was written. Say which of the two reasons it was.
     *
     * @throws DomainException a duplicate — the guard doing its job
     * @throws RuntimeException a write that failed and was downgraded to a warning
     */
    private function refuseOrFail(Attempt $attempt, int $questionId): never
    {
        $exists = Answer::query()
            ->withoutWorkspaceScope()
            ->where('attempt_id', $attempt->getKey())
            ->where('question_id', $questionId)
            ->exists();

        if ($exists) {
            throw new DomainException('تمّت الإجابة عن هذا السؤال من قبل.');
        }

        throw new RuntimeException(
            "Answer insert wrote no row and no duplicate exists (attempt {$attempt->getKey()}, question {$questionId})."
        );
    }
}
