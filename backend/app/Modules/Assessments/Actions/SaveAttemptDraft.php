<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Models\Attempt;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Keep the answers a student has chosen so far on an open, timed sitting
 * (owner decision 2026-10-29) — the paper the clock marks if they never press
 * «سلّم» (`GradeAttempt`, `CloseTimedOutAttempts`).
 *
 * ⚠️ ONE CONDITIONAL UPDATE: the status is asked in the same statement that
 * writes, so a save racing the submit's claim lands on an `in_progress` row or
 * not at all — never on a paper already being marked.
 */
class SaveAttemptDraft extends Action
{
    public const CLOSED_REFUSAL = 'انتهى وقت هذا الاختبار، فلم تُحفَظ هذه الإجابة.';

    /**
     * @param  array<int, array{question_id: int, selected_option_ids?: array<int>, answer_text?: string|null}>  $answers
     *
     * @throws DomainException when the attempt is no longer open, or its time is up
     */
    public function handle(Attempt $attempt, array $answers): void
    {
        if ($attempt->isClosedByClock()) {
            throw new DomainException(self::CLOSED_REFUSAL);
        }

        $saved = Attempt::query()->withoutWorkspaceScope()
            ->whereKey($attempt->getKey())
            ->where('status', Attempt::STATUS_IN_PROGRESS)
            ->update(['draft_answers' => json_encode(array_values($answers), JSON_UNESCAPED_UNICODE)]);

        if ($saved !== 1) {
            throw new DomainException('سُلِّمت هذه المحاولة بالفعل.');
        }
    }
}
