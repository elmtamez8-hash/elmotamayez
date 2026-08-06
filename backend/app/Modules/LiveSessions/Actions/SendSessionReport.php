<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSessionFeedback;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Actions\Action;

/**
 * One register row, told to the people entitled to hear it.
 *
 * Called twice from two places for the same row: once when the delay after the
 * session elapses, and again as a correction if the teacher edits the row
 * afterwards (FR-037). Both go through the same composition, so a correction
 * cannot describe the student differently from the report it corrects.
 *
 * Note what this does NOT decide: who receives it. The request names the student
 * as the subject and Notifications fans out to whichever guardians hold the
 * attendance consent (FR-033) — so a student with two guardians reaches both
 * from one call here, and a student with none still reaches themselves. Naming
 * the guardians here would put a second, drifting copy of that rule in a module
 * that does not own the relation.
 *
 * And no channel is named anywhere in this file. That is the addendum's
 * constraint, and ProviderAgnosticTest fails the build if it reappears.
 */
class SendSessionReport extends Action
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(Attendance $attendance, bool $isCorrection = false): void
    {
        $session = $attendance->classSession;
        $student = $attendance->student;

        if ($session === null || $student === null) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::SessionReport,
            variables: [
                'title' => $session->title,
                'student_name' => $student->name,
                'status' => $attendance->status->label(),
                'minutes' => (string) (int) round($attendance->stay_seconds / 60),
                'note' => $this->note($attendance, $isCorrection),
            ],
            actionUrl: '/schedule',
            subject: $student,
            workspaceId: (int) $attendance->workspace_id,
        ));

        $attendance->forceFill(['report_sent_at' => now()])->save();
    }

    /**
     * The teacher's remark, or the reason there is none.
     *
     * A template variable that renders empty refuses the whole message
     * (TemplateRenderer), and FR-035 forbids holding the report back until a
     * remark arrives — so "no remark" is a sentence, not a blank.
     */
    private function note(Attendance $attendance, bool $isCorrection): string
    {
        $feedback = ClassSessionFeedback::query()
            ->where('class_session_id', $attendance->class_session_id)
            ->where('student_user_id', $attendance->student_user_id)
            ->first();

        $note = trim((string) ($feedback === null ? '' : $feedback->note));
        $note = $note === '' ? 'لا ملاحظات إضافية.' : $note;

        // Said first, because a guardian reading the second report of the day
        // must know which one is current before they read the status.
        return $isCorrection ? 'تصحيح لتقرير سابق. '.$note : $note;
    }
}
