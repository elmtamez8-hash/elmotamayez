<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Events\AttendanceConfirmed;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * The instant absence alert (`attendance_alert`, owner decision 2026-09-24).
 *
 * The register became final and a student is marked absent ⇒ that student and
 * every guardian holding the ATTENDANCE consent are told now, not after the
 * report's delay. The fan-out is `DispatchNotification`'s: the type targets
 * guardians and names `GuardianPermission::Attendance`, the same consent the
 * post-session report rides.
 *
 * ⚠️ WHY `AttendanceConfirmed` AND NOTHING ELSE. It fires from exactly one line,
 * `CloseClassSession`, after the conditional UPDATE that only one closer wins —
 * so it fires once in a session's life. `AbandonClassSession` (nobody held the
 * class) writes no register and fires nothing; a cancelled session is terminal
 * and never reaches the close. And `AttendanceOverridden` is deliberately NOT
 * heard: a mark a teacher changes afterwards is corrected by the report's own
 * correction path, and a later change TO absent is not a second alert.
 *
 * ⚠️ AND ONLY ON A DELIVERED SESSION. `CloseClassSession` fires the event even
 * when the teacher opened the room and left before the lesson counted
 * (`delivered_at` null) — every student who stayed longer than the teacher is
 * then `absent` in the register, and telling their parents they skipped a class
 * the teacher walked out of is the wrong sentence to send. `delivered_at` is the
 * product's own "the class was held".
 *
 * Who is NOT alerted, each for a reason:
 *  · the host — they have a row on purpose (`excludingHost()`);
 *  · a seat the teacher EXCUSED before the close (`session_bookings.excused_at`)
 *    — the attendance row stays `absent` there, the excuse lives on the booking
 *    (`ExcusedTwoMeaningsTest`), and the parent of an excused child is not told
 *    they missed the class;
 *  · a student the teacher REMOVED (`removed_at`) — they were in the room, and
 *    "did not attend" is false of them.
 *
 * ⚠️ IDEMPOTENT BY CLAIM, THE DORMANCY-NOTICE ORDER. `absence_alerted_at` is
 * stamped by a conditional UPDATE BEFORE the dispatch, so a redelivered listener
 * finds nothing to claim; a lost alert beats a duplicate. The one exception is
 * a dispatch that recorded NOTHING (a missing or unapproved template, dropped by
 * design): the claim is handed back, because a stamp over a message nobody
 * received would be a lie the guard then keeps for ever.
 *
 * Queued and after commit, the `AccrueUnitsOnDelivery` rule: one synchronous
 * listener among queued ones is a single point of failure for everything
 * dispatched after it in `CloseClassSession`.
 */
class SendAbsenceAlerts implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
        private readonly SessionSettings $settings,
        private readonly WorkspaceContext $context,
    ) {}

    public function handle(AttendanceConfirmed $event): void
    {
        // Re-read: the event is not `SerializesModels`, and a queued listener
        // would otherwise judge the attributes as they stood at dispatch.
        $session = ClassSession::query()->withoutWorkspaceScope()->find($event->session->getKey());

        if ($session === null || $session->delivered_at === null) {
            return;
        }

        $this->context->forWorkspace((int) $session->workspace_id, function () use ($session): void {
            $excused = DB::table('session_bookings')
                ->where('class_session_id', $session->getKey())
                ->whereNotNull('excused_at')
                ->pluck('student_user_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $rows = $session->attendances()
                ->excludingHost($session)
                ->where('status', AttendanceStatus::Absent->value)
                ->whereNull('removed_at')
                ->whereNull('absence_alerted_at')
                ->whereNotIn('student_user_id', $excused === [] ? [0] : $excused)
                ->with('student')
                ->get();

            $when = $session->starts_at
                ->copy()
                ->setTimezone($this->settings->timezone())
                ->format('Y-m-d H:i');

            foreach ($rows as $attendance) {
                $this->alert($session, $attendance, $when);
            }
        });
    }

    private function alert(ClassSession $session, Attendance $attendance, string $when): void
    {
        $student = $attendance->student;

        if ($student === null) {
            return;
        }

        $claimed = DB::table('attendances')
            ->where('id', $attendance->getKey())
            ->whereNull('absence_alerted_at')
            ->update(['absence_alerted_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $sent = $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::AttendanceAlert,
            // The template's own variable names and ORDER — the WhatsApp row's
            // numbered placeholders mean these three, in this order.
            variables: [
                'student_name' => $student->name,
                'session_title' => $session->title,
                'session_date' => $when,
            ],
            actionUrl: '/schedule',
            subject: $student,
            workspaceId: (int) $session->workspace_id,
        ));

        if ($sent->isEmpty()) {
            DB::table('attendances')
                ->where('id', $attendance->getKey())
                ->update(['absence_alerted_at' => null]);
        }
    }
}
