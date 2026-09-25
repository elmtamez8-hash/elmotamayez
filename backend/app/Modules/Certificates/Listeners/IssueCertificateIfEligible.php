<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Listeners;

use App\Modules\Certificates\Actions\IssueCertificate;
use App\Modules\Learning\Events\CourseCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Issues the course certificate when — and only when — the course is completed.
 *
 * ⛔ **قرارُ المالك (٢٠٢٦-٠٩-٢٥): شهادةُ الكورسِ تُصدَرُ بإتمامِ الكورسِ وحدَه.**
 * This listener used to answer `ExamPassed` as well, with reason `exam_passed`,
 * and that issued the WHOLE-COURSE certificate on any passed exam linked to the
 * course — a week-one quiz sat at 10% progress was enough. A certificate that
 * says the course was completed, over a student who has not completed it, is
 * the one document this product signs, and nothing withdraws it.
 *
 * ⚠️ **An exam that is the course's last item still ends in a certificate**, by
 * the road every other item takes: `CompleteExamLessonOnSubmission` ticks the
 * exam item when its gate is answered — on `ExamSubmitted`, and again on
 * `ExamPassed` for a paper whose pass arrives only after an essay is marked —
 * and completing the last item fires `CourseCompleted`. Removing the exam-pass
 * issuance WITHOUT that second trigger would have left every course ending in a
 * pass-gated essay exam with no certificate at all, permanently.
 *
 * Certificates already issued with `issue_reason = exam_passed` are left
 * standing: they were issued under the rule of their day, and `labels.ts` still
 * renders the reason.
 *
 * `IssueCertificate` is idempotent (unique on workspace+enrollment+course).
 * Queued so certificate creation and PDF generation do not block the request or
 * run inside the enrolment's transaction.
 */
class IssueCertificateIfEligible implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly IssueCertificate $action,
    ) {}

    public function handleCourseCompleted(CourseCompleted $event): void
    {
        $this->action->handle($event->enrollment, 'course_completed');
    }
}
