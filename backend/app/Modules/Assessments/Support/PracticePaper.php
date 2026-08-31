<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes a practice attempt and freezes the questions into it.
 *
 * ⚠️ ONE WRITER FOR TWO GENERATORS. "Test me on my mistakes" and the
 * self-generated exam differ only in how they CHOOSE the questions; what they do
 * with them afterwards is identical, and a second copy of these two writes is a
 * second place for `is_practice` or `exam_id` to be got wrong. Both of those are
 * load-bearing: the flag keeps a revision run out of the grade report (FR-025)
 * and the null exam keeps it from spending an official attempt (FR-026أ).
 */
class PracticePaper
{
    /**
     * @param  Collection<int, Question>  $questions  in the order they will be shown, options loaded
     */
    public function write(int $workspaceId, User $student, Collection $questions): Attempt
    {
        return DB::transaction(function () use ($workspaceId, $student, $questions): Attempt {
            $attempt = Attempt::create([
                'workspace_id' => $workspaceId,
                // No exam behind it: a generated paper is not something a teacher
                // authored, and the alternative — a row in `exams` per
                // generation — is a student writing into the teacher's own table.
                'exam_id' => null,
                'enrollment_id' => null,
                'student_user_id' => $student->getKey(),
                'status' => Attempt::STATUS_IN_PROGRESS,
                'is_practice' => true,
                // `random_seed` is NOT NULL and 32-bit unsigned; a wider value is
                // rejected outright by strict MySQL.
                'random_seed' => random_int(1, 2147483647),
                'started_at' => now(),
            ]);

            foreach ($questions->values() as $index => $question) {
                AttemptItem::create([
                    'workspace_id' => $workspaceId,
                    'attempt_id' => $attempt->getKey(),
                    'question_id' => $question->getKey(),
                    'order' => $index + 1,
                    'points' => (int) $question->points,
                    'snapshot' => QuestionSnapshot::of($question),
                ]);
            }

            return $attempt;
        });
    }

    /**
     * Freeze ONE more question into a paper that is already open.
     *
     * Spec 012's adaptive path chooses the next question from how the last one
     * went, so it cannot write the whole paper up front — but what it does with a
     * question once chosen must be identical to what {@see write()} does, or
     * `is_practice`, the null exam or the snapshot shape drift between the two
     * generators the class exists to keep in step.
     *
     * ⚠️ `max(order) + 1` IS A READ FOLLOWED BY A WRITE, and it is safe here for
     * one reason only: a session serves a question and then waits for its answer,
     * and answering is guarded by `unique(attempt_id, question_id)` — so there is
     * never a second serve in flight. It is a display order and nothing else;
     * every answer is addressed by `question_id`, which is the column the
     * uniqueness is on.
     */
    public function append(Attempt $attempt, Question $question): AttemptItem
    {
        return AttemptItem::create([
            'workspace_id' => $attempt->workspace_id,
            'attempt_id' => $attempt->getKey(),
            'question_id' => $question->getKey(),
            'order' => (int) $attempt->items()->max('order') + 1,
            'points' => (int) $question->points,
            'snapshot' => QuestionSnapshot::of($question),
        ]);
    }
}
