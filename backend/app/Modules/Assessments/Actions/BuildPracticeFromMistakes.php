<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Support\MistakeNotebook;
use App\Modules\Assessments\Support\PracticePaper;
use App\Modules\Assessments\Support\PracticePool;
use App\Shared\Actions\Action;
use DomainException;

/**
 * "Test me on my mistakes" (FR-018).
 *
 * ⚠️ THE STANDING MISTAKES ONLY. A question already answered correctly since is
 * excluded by default (FR-019) — a revision loop that keeps returning what the
 * student has already fixed is a loop they stop using after the second round.
 * The definition of "standing" is asked of {@see MistakeNotebook} rather than
 * rewritten here: two definitions of one rule are two answers the day either
 * moves.
 *
 * ⚠️ AND THE ATTEMPT BELONGS TO NO EXAM. `exam_id` is null and `is_practice` is
 * true, which together mean it spends no official attempt (FR-026أ), enters no
 * grade report (FR-025), and fires no `ExamPassed`. The alternative — a row in
 * `exams` per generation — is a student writing into a table the teacher owns,
 * and their exam list filling with machine noise.
 */
class BuildPracticeFromMistakes extends Action
{
    /** How many questions one revision session may hold. */
    private const MAX_QUESTIONS = 20;

    public function __construct(
        private readonly MistakeNotebook $notebook,
        private readonly PracticePool $pool,
        private readonly PracticePaper $paper,
    ) {}

    /**
     * @param  array{concept?: string, lesson?: string, from?: string, to?: string}  $filters
     *
     * @throws DomainException when nothing is left to practise
     */
    public function handle(int $workspaceId, User $student, array $filters = [], int $limit = 10): Attempt
    {
        $limit = max(1, min($limit, self::MAX_QUESTIONS));

        $questionIds = $this->notebook->standingQuestionIds(
            $workspaceId,
            (int) $student->getKey(),
            $filters,
            // Fetched wider than asked for, because the two exclusions below can
            // remove most of what came back — asking for exactly `$limit` and
            // then filtering is how a student with eleven mistakes gets a
            // three-question paper.
            self::MAX_QUESTIONS * 5,
        );

        $withheld = $this->pool->withheldQuestionIds($workspaceId, (int) $student->getKey());

        $questions = Question::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $questionIds)
            ->whereNotIn('id', $withheld)
            // ⚠️ Disabled stays IN the notebook and out of the paper. The mistake
            // happened and the student may still read it; a question the teacher
            // withdrew must not be put back in front of anyone.
            ->where('is_active', true)
            // ⚠️ And no essays. Practice is marked instantly (FR-024), while an
            // essay would push the attempt to `pending_grading` — dropping work
            // the student set themselves into a teacher's marking queue.
            ->where('type', '!=', 'essay')
            ->with('options')
            ->get()
            ->sortBy(fn (Question $question) => array_search((int) $question->getKey(), $questionIds, true))
            ->take($limit)
            ->values();

        if ($questions->isEmpty()) {
            throw new DomainException('لا أخطاء قائمة لبناء اختبارٍ منها.');
        }

        return $this->paper->write($workspaceId, $student, $questions);
    }
}
