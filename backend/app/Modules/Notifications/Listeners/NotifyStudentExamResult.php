<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Assessments\Events\AttemptFinalized;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The result is final — say so, once (FR-030).
 *
 * ⚠️ `AttemptFinalized` FIRES FOR PRACTICE RUNS TOO, deliberately: spec 009
 * counts "solved ten questions on your own" off it. So the skip belongs here.
 * Without it every self-set revision paper pings its own author with a result
 * they watched being marked on screen a second earlier.
 */
class NotifyStudentExamResult implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(AttemptFinalized $event): void
    {
        $attempt = $event->attempt;

        if ($attempt->is_practice) {
            return;
        }

        $student = $attempt->student;

        if ($student === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::ExamResult,
            variables: [
                'student_name' => $student->name,
                'exam_title' => $attempt->exam === null ? 'اختبار' : $attempt->exam->title,
                'score' => rtrim(rtrim(number_format((float) $attempt->score, 2, '.', ''), '0'), '.').'٪',
            ],
            // ⚠️ `/exams/{attempt}/result`. There is no `/exams/attempts/…`
            // route — the result screen takes the ATTEMPT uuid in the segment
            // its folder calls `[uuid]`, which is what made the wrong guess look
            // plausible.
            actionUrl: '/exams/'.$attempt->uuid.'/result',
            workspaceId: (int) $attempt->workspace_id,
        ));
    }
}
