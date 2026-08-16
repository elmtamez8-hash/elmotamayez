<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Events\AttemptFinalized;
use App\Modules\Assessments\Events\ExamFailed;
use App\Modules\Assessments\Events\ExamPassed;
use App\Modules\Assessments\Models\Attempt;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Support\Facades\DB;

/**
 * The single place a score becomes final, and the only place `ExamPassed` fires.
 *
 * ⚠️ THIS EXISTS BECAUSE OF THE CERTIFICATE CONTRACT. Before spec 008 the pass
 * event fired at submission, when every question was machine-marked and the
 * score at submission WAS the final score. Essays break that equality: a
 * submitted attempt can hold half its marks. Firing then issues a certificate
 * for half an exam — and `IssueCertificateIfEligible` is idempotent, so it will
 * not issue a second one, but nothing withdraws the first.
 *
 * The event's shape does not change. Only the moment it fires does, so no
 * listener in Certificates or Notifications is touched.
 */
class FinalizeAttempt extends Action
{
    use LogsActivity;

    public function handle(Attempt $attempt): Attempt
    {
        return DB::transaction(function () use ($attempt): Attempt {
            $answers = $attempt->answers()->get();
            $items = $attempt->items()->get();

            $totalPoints = (int) $items->sum('points');
            // ⚠️ FLOAT, NOT INT. A rubric awards halves, and an `(int)` here
            // rounds the whole paper down once per essay — after the mark scheme
            // has already been validated as adding up.
            $earnedPoints = (float) $answers->sum('points');

            $maxScore = max($totalPoints, 1);
            $scorePct = round(($earnedPoints / $maxScore) * 100, 2);

            // A self-generated practice run belongs to no exam anybody authored,
            // so there is no teacher's threshold to ask for. It is still scored
            // and still shown; what it does not do is pass or fail anything, which
            // is why the branch below returns before the pass/fail events.
            $exam = $attempt->exam;
            $passingScore = $exam !== null ? (int) $exam->passing_score : 60;
            $passed = $scorePct >= $passingScore;

            $attempt->update([
                'status' => Attempt::STATUS_GRADED,
                'score' => $scorePct,
                'max_score' => 100,
                /*
                 | ⚠️ A PRACTICE RUN IS STORED AS `passed = false` WHATEVER IT
                 | SCORED, and this line used to write the computed value while
                 | the comment below claimed the opposite. Suppressing the EVENT
                 | is not enough: `passed` is a COLUMN, and the first reader who
                 | writes `where('passed', true)` — a transcript, a report, a
                 | certificate sweep — counts a paper the student set themselves
                 | as a paper they passed. FR-025 forbids exactly that, and the
                 | disagreement between a comment and its code is the kind that
                 | survives review because both halves read correctly on their own.
                 */
                'passed' => $attempt->is_practice ? false : $passed,
                'finalized_at' => now(),
            ]);

            $attempt->refresh();

            // ⚠️ The STORED value, not the computed one. An audit line that says
            // "passed" over a row that says otherwise is the same disagreement as
            // above, one layer down — and the layer whose whole job is to be
            // believed later.
            $this->logActivity('finalized', $attempt, [
                'score' => $scorePct,
                'passed' => (bool) $attempt->passed,
            ]);

            event(new AttemptFinalized($attempt));

            /*
             | A practice attempt emits no pass/fail. Those two are the graded
             | record of the course — the certificate chain hangs off them — and a
             | revision session is not a sitting. FR-025 says a practice attempt
             | may not enter any official reading of the grades, and an event that
             | issues a certificate is the most official reading there is.
             */
            if ($attempt->is_practice) {
                return $attempt;
            }

            event($passed
                ? new ExamPassed($attempt)
                : new ExamFailed($attempt));

            return $attempt;
        });
    }
}
