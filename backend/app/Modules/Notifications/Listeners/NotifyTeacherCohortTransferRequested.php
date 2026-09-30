<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Learning\Events\CohortTransferRequested;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * «يطلب سامي الانتقال إلى الأحد ٦م» (FR-028ح).
 *
 * ⚠️ `ShouldQueueAfterCommit`, BECAUSE THE EVENT FIRES INSIDE THE
 * TRANSACTION THAT WRITES THE REQUEST. Without it a real queue worker picks the
 * job up before the commit lands, reads no row, and the teacher's queue never
 * lights up — invisibly, and only on the `redis` connection production runs, not
 * on the `sync` one every test uses.
 *
 * The recipient is the course's TEACHER (`Course::teacherUser()`): its creator
 * when the creator teaches in the workspace, the recorded teacher profile's
 * person when the creator was an ASSISTANT — who used to receive every transfer
 * request for it (fixed 2026-09-30).
 */
class NotifyTeacherCohortTransferRequested implements ShouldQueueAfterCommit
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(CohortTransferRequested $event): void
    {
        $request = $event->request;
        $course = $request->course;
        $student = $request->student;
        $teacher = $course?->teacherUser();

        // A course may have no teacher left — it can outlive its author — and a
        // student whose account is gone has nothing to say. Neither is an error.
        if ($course === null || $student === null || $teacher === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::CohortTransferRequested,
            variables: [
                'student_name' => $student->name,
                'course_title' => $course->title,
                'from_cohort' => $request->fromCohort->name,
                'to_cohort' => $request->toCohort->name,
            ],
            // The queue itself, not the course page: the teacher opens this to
            // press one of two buttons.
            actionUrl: '/manage/courses/'.$course->uuid.'/cohorts',
            subject: $student,
            workspaceId: (int) $request->workspace_id,
        ));
    }
}
