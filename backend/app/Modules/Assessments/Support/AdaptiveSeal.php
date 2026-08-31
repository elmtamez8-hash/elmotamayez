<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Models\AdaptiveSession;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;

/**
 * Seal the attempt behind a session that has just closed.
 *
 * ⚠️ ONE SPELLING FOR THREE ENDINGS. A session closes by being mastered, by
 * running out of questions, or by the student pressing stop — and all three must
 * leave the attempt in the same state, or a paper reads as still open depending
 * on how the student happened to finish it. That row is what
 * `EloquentStudentGradeDirectory` and the mistake notebook both read.
 *
 * ⚠️ AND IT DOES NOT GO THROUGH `GradeAttempt`, which writes an answer row for
 * every item and would collide with the rows already written question by
 * question. It raises no `AttemptFinalized` either: that event's notification
 * listener fires for practice attempts deliberately, so every session would end
 * with a «نتيجة اختبار» message about a revision run.
 */
class AdaptiveSeal
{
    /**
     * Called by whoever WON the closure claim, and by nobody else — a second
     * seal would restamp `submitted_at` on a paper that was handed in already.
     */
    public function seal(AdaptiveSession $session): void
    {
        $this->sealAttempt((int) $session->attempt_id);
    }

    /**
     * The same seal, addressed by attempt id — the FOURTH ending.
     *
     * Spec 012's study room finishes a paper too, and the docblock's argument
     * applies to it word for word: a room paper left `in_progress` reads as still
     * open to `EloquentStudentGradeDirectory` and to the mistake notebook,
     * depending only on which surface the student happened to finish it from. The
     * session-shaped signature above is kept because that is what its three
     * callers hold.
     */
    public function sealAttempt(int $attemptId): void
    {
        $earned = (float) Answer::query()
            ->withoutWorkspaceScope()
            ->where('attempt_id', $attemptId)
            ->sum('points');

        // The denominator is what was SERVED, not the whole bank: the paper here
        // is exactly as long as the student made it.
        $total = (float) AttemptItem::query()
            ->withoutWorkspaceScope()
            ->where('attempt_id', $attemptId)
            ->sum('points');

        Attempt::query()
            ->withoutWorkspaceScope()
            ->whereKey($attemptId)
            ->update([
                'status' => Attempt::STATUS_GRADED,
                'score' => round(($earned / max($total, 1.0)) * 100, 2),
                'max_score' => 100,
                // A practice run neither passes nor fails: there is no exam
                // behind it and so no passing score to compare against.
                'passed' => false,
                'submitted_at' => now(),
                'finalized_at' => now(),
            ]);
    }
}
