<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Models\AttemptItem;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Support\Facades\DB;

/**
 * Takes a question out of circulation.
 *
 * ⚠️ FR-005: A QUESTION THAT HAS BEEN SAT IS NEVER DELETED. Its id is written
 * into `exam_answers.question_id` and into every `attempt_items` snapshot, so a
 * hard delete either orphans a graded paper or cascades it away — and the
 * teacher pressing the button is trying to tidy their bank, not to erase a
 * student's result. Disabling stops it being offered and changes nothing that
 * already happened.
 *
 * A question nobody has answered yet is a different object: nothing depends on
 * it, so it is deleted for real. Keeping every draft typo for ever as a
 * greyed-out row is how the bank becomes unusable in a term.
 */
class DisableQuestion extends Action
{
    use LogsActivity;

    /**
     * @return bool `true` when the row was deleted, `false` when it was disabled
     */
    public function handle(Question $question): bool
    {
        return DB::transaction(function () use ($question): bool {
            if ($this->hasHistory($question)) {
                $question->update(['is_active' => false]);

                $this->logActivity('disabled', $question);

                return false;
            }

            // ⚠️ THE CHILDREN ARE DELETED BY HAND BECAUSE NOTHING ELSE WILL.
            // `question_options.question_id` and `exam_items.question_id` carry an
            // index and no foreign key, so the database neither cascades nor
            // refuses — it accepts the parent's deletion and leaves the children
            // pointing at nothing. Options then surface in the next question that
            // happens to reuse the id.
            //
            // Inclusions in an exam are not history: nobody has sat them, so they
            // go with the question and the exam simply has one fewer item.
            $question->options()->delete();
            ExamItem::query()->where('question_id', $question->getKey())->delete();

            $this->logActivity('deleted', $question);

            $question->delete();

            return true;
        });
    }

    /**
     * Has anyone ever been shown this question?
     *
     * Asked of `attempt_items` rather than `exam_answers`: the snapshot is
     * written when an attempt STARTS, so a sitting still in progress is already
     * history — and deleting the question underneath it is exactly the case the
     * snapshot was introduced to survive.
     */
    private function hasHistory(Question $question): bool
    {
        return AttemptItem::query()->where('question_id', $question->getKey())->exists();
    }
}
