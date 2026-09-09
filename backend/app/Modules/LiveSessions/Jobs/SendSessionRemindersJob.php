<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Contracts\SessionAttendanceDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * «حصّتك تبدأ بعد ساعة» — the sender `appointment_reminder` never had.
 *
 * ⚠️ THE TYPE EXISTED AND NOTHING WROTE IT. `NotificationType::AppointmentReminder`
 * has had an enum case, an Arabic label, a category, guardian targeting on the
 * `Schedule` consent and a seeded template since spec 003 — and not one line in
 * `app/` ever dispatched it. So no student on this platform has ever been
 * reminded of a lesson, and every screen said the feature was there. That is the
 * family this repository records under «an enum value with three readers and no
 * writer is a requirement everybody believed was implemented» —
 * `ClassSessionStatus::Interrupted`, `courses.grade_level`, `courses.course_type`.
 *
 * ⚠️ THE AUDIENCE IS THE SEAT HOLDERS, NOT THE ENROLLED. A course's students are
 * not this hour's students — 021 exists to keep Saturday's group out of Sunday's
 * lesson — and `seatHolderUserIds()` is the one spelling of «who is in this
 * room» that every other door in this module already reads.
 *
 * ⚠️ `reminded_at` IS CLAIMED BY A CONDITIONAL UPDATE, BEFORE ANYTHING IS SENT.
 * «starting within the next N minutes» is true again on the next pass, so
 * without the mark every phone buzzes every few minutes until the lesson begins
 * — and a family that mutes the channel over that loses the absence alert with
 * it. Stamping first also makes two overlapping workers safe: the loser's UPDATE
 * matches zero rows and it sends nothing. The cost is that a worker killed
 * between the stamp and the dispatch loses one reminder, which is the trade
 * `notified_dormant_at` already made and wrote down.
 *
 * ⚠️ AND A GUARDIAN IS REACHED HERE FOR THE FIRST TIME. `AppointmentReminder`
 * declares `targetsGuardians()` with `GuardianPermission::Schedule`, so it
 * carries the WhatsApp channel by derivation — the moment this job exists, paid
 * messages start leaving the platform. That is the design (a parent arranges the
 * afternoon around the lesson), not an oversight, and it is written here because
 * nothing else in the diff says so.
 *
 * ⚠️ `WithoutOverlapping` AS JOB MIDDLEWARE with `expireAfter()`, the
 * `ExpirePrivateSessionRequestsJob` shape: the schedule's own
 * `withoutOverlapping()` guards the DISPATCH, which for a queued job is
 * milliseconds around the push — and a lock with no expiry left by a killed
 * worker means reminders silently never run again.
 *
 * ⚠️ AND THE PER-ROW `try/catch` SHIPS WITH IT. One unresolvable session must
 * not kill the pass that exists to serve the others — the `CloseStaleSessionsJob`
 * precedent, where one bad `broadcast_provider` took out the whole hourly sweep
 * on its first row.
 */
class SendSessionRemindersJob implements ShouldQueue
{
    use Queueable;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('session-reminders'))->expireAfter(600)];
    }

    public function handle(
        SessionSettings $settings,
        SessionAttendanceDirectory $attendance,
        DispatchNotification $dispatch,
    ): void {
        $now = now();
        $until = $now->copy()->addMinutes($settings->reminderLeadMinutes());

        ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereNull('reminded_at')
            ->where('status', ClassSessionStatus::Scheduled->value)
            /*
            | Both bounds. The upper one is the lead time; the LOWER one is what
            | stops a pass that has been down for a day announcing every lesson
            | it slept through — a reminder for an hour that already started is
            | worse than none, because it is read as «it is starting now».
            */
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $until)
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use ($attendance, $dispatch, $settings): void {
                foreach ($sessions as $session) {
                    try {
                        $this->remind($session, $attendance, $dispatch, $settings);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
    }

    private function remind(
        ClassSession $session,
        SessionAttendanceDirectory $attendance,
        DispatchNotification $dispatch,
        SessionSettings $settings,
    ): void {
        /*
        | ⚠️ THE CLAIM IS BOTH THE CHECK AND THE MARK — the seat idiom, and never
        | a read followed by a save. Two workers overlapping on one session both
        | read `reminded_at` as null, both send, and every student is told twice.
        | Never `lockForUpdate()`, a no-op on SQLite.
        */
        $claimed = ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereKey($session->getKey())
            ->whereNull('reminded_at')
            ->update(['reminded_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $seatHolderIds = $attendance->seatHolderUserIds(
            (int) $session->getKey(),
            (int) $session->workspace_id,
        );

        if ($seatHolderIds === []) {
            return;
        }

        $startsAt = $session->starts_at
            ->copy()
            ->setTimezone($settings->timezone())
            ->format('Y-m-d H:i');

        foreach (User::query()->whereIn('id', $seatHolderIds)->get() as $student) {
            $dispatch->handle(new NotificationRequest(
                recipient: $student,
                type: NotificationType::AppointmentReminder,
                // The template's three, and no more: `TemplateRenderer` counts a
                // present-but-blank variable as MISSING and drops the whole
                // message in silence.
                variables: [
                    'student_name' => $student->name,
                    'session_title' => $session->title,
                    'starts_at' => $startsAt,
                ],
                actionUrl: '/schedule',
                subject: $student,
                workspaceId: (int) $session->workspace_id,
            ));
        }
    }
}
