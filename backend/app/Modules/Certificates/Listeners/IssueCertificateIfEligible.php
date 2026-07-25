<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Listeners;

use App\Modules\Assessments\Events\ExamPassed;
use App\Modules\Certificates\Actions\IssueCertificate;
use App\Modules\Learning\Events\CourseCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Listener that issues a certificate when either CourseCompleted or ExamPassed fires.
 *
 * The IssueCertificate action is idempotent (DB unique constraint on
 * workspace_id+enrollment_id+course_id), so both triggers pointing at the same
 * enrollment will produce exactly one certificate.
 *
 * Queued so certificate creation + PDF generation don't block the HTTP response
 * or run inside the enrollment's DB transaction.
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

    public function handleExamPassed(ExamPassed $event): void
    {
        $attempt = $event->attempt;

        // Link to the enrollment if one exists for this attempt.
        $enrollment = $attempt->enrollment;

        if ($enrollment === null) {
            // Standalone exam (no course/enrollment) — certificate needs an enrollment in our schema.
            // Skip for MVP; only course-linked exams issue certificates.
            return;
        }

        $this->action->handle($enrollment, 'exam_passed', $attempt->getKey());
    }
}
