<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Sets an exam's questions to exactly the list it is given.
 *
 * ⚠️ THE COMPLETE LIST, NEVER A PARTIAL EDIT. Same reason reordering a course
 * tree takes every sibling: two teachers editing one exam from two tabs each
 * send the change they made, both succeed, and the exam ends up holding neither
 * arrangement. A whole list is a state the caller saw; an add and a remove are
 * two operations against a state that may have moved.
 *
 * ⚠️ AND IT IS REFUSED ONCE THE EXAM HAS BEEN SAT. `attempt_items` freezes what
 * a student was shown, so a live attempt survives the edit — but the exam's own
 * denominator would change under students who are yet to sit it, in the middle
 * of the window where half the class already has. Draft exams are edited freely.
 */
class SyncExamItems extends Action
{
    use LogsActivity;

    /**
     * @param  list<array{uuid: string, points_override?: int|null}>  $items  in the order they will be shown
     * @return list<ExamItem>
     *
     * @throws DomainException
     */
    public function handle(Exam $exam, array $items): array
    {
        return DB::transaction(function () use ($exam, $items): array {
            $this->guardSat($exam);

            $questions = $this->resolve($exam, $items);

            $keep = [];

            foreach ($items as $order => $item) {
                $question = $questions[$item['uuid']];

                /** @var ExamItem $row */
                $row = ExamItem::query()->updateOrCreate(
                    ['exam_id' => $exam->getKey(), 'question_id' => $question->getKey()],
                    [
                        'workspace_id' => $exam->workspace_id,
                        'order' => $order + 1,
                        'points_override' => $item['points_override'] ?? null,
                    ],
                );

                $keep[] = $row;
            }

            // Everything the caller did not send is gone. This is the half a
            // partial edit cannot express.
            ExamItem::query()
                ->where('exam_id', $exam->getKey())
                ->whereNotIn('id', array_map(static fn (ExamItem $row): int => (int) $row->getKey(), $keep))
                ->delete();

            $this->logActivity('items_synced', $exam, ['count' => count($keep)]);

            return $keep;
        });
    }

    /**
     * Every uuid into a question of THIS workspace, or the whole call fails.
     *
     * A missing uuid is not skipped: the caller sent a list it believed was the
     * exam, and quietly dropping one entry hands back an exam one question
     * shorter than the screen that submitted it.
     *
     * @param  list<array{uuid: string, points_override?: int|null}>  $items
     * @return array<string, Question>
     *
     * @throws DomainException
     */
    private function resolve(Exam $exam, array $items): array
    {
        $uuids = array_values(array_unique(array_map(
            static fn (array $item): string => (string) $item['uuid'],
            $items,
        )));

        if (count($uuids) !== count($items)) {
            throw new DomainException('A question cannot appear twice in one exam.');
        }

        /** @var array<string, Question> $questions */
        $questions = Question::query()
            ->where('workspace_id', $exam->workspace_id)
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid')
            ->all();

        foreach ($uuids as $uuid) {
            if (! isset($questions[$uuid])) {
                throw new DomainException("No question {$uuid} in this bank.");
            }

            if (! $questions[$uuid]->is_active) {
                throw new DomainException('A disabled question cannot be added to an exam.');
            }
        }

        return $questions;
    }

    /**
     * Has anyone sat this exam for real?
     *
     * Practice runs are excluded deliberately. A revision sitting consumes no
     * attempt and carries no result (FR-026أ), so letting one freeze the exam
     * would hand any student the power to lock their teacher out of it.
     *
     * @throws DomainException
     */
    private function guardSat(Exam $exam): void
    {
        $sat = Attempt::query()
            ->where('exam_id', $exam->getKey())
            ->where('is_practice', false)
            ->exists();

        if ($sat) {
            throw new DomainException('لا يمكن تعديل أسئلة اختبارٍ بدأت محاولاته.');
        }
    }
}
