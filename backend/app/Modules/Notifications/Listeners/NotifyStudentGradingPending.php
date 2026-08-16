<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Assessments\Events\AttemptPendingGrading;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Tells the student their paper is in but not finished.
 *
 * ⚠️ WITHOUT THIS THEY READ THE AUTO-SCORE AS THEIR RESULT. An attempt waiting
 * on an essay carries the machine-marked total and nothing else — a student who
 * answered every essay perfectly sees the mark for the multiple choice alone and
 * concludes they failed. This message is the difference between "not finished"
 * and "this is what you got".
 */
class NotifyStudentGradingPending implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(AttemptPendingGrading $event): void
    {
        $attempt = $event->attempt;

        // A revision paper the student set themselves has no grader waiting on
        // it and no result to announce.
        if ($attempt->is_practice) {
            return;
        }

        $student = $attempt->student;

        if ($student === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::ExamPendingGrading,
            variables: [
                'student_name' => $student->name,
                'exam_title' => $attempt->exam === null ? 'اختبار' : $attempt->exam->title,
            ],
            actionUrl: '/exams/attempts/'.$attempt->uuid,
            workspaceId: (int) $attempt->workspace_id,
        ));
    }
}
