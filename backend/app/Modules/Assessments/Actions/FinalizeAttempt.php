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
            $earnedPoints = (int) $answers->sum('points');

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
                'passed' => $passed,
                'finalized_at' => now(),
            ]);

            $attempt->refresh();

            $this->logActivity('finalized', $attempt, [
                'score' => $scorePct,
                'passed' => $passed,
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
