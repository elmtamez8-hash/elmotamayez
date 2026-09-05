<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Models\User;
use App\Modules\LiveSessions\Actions\ClaimSubscriptionSeats;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Taking a month of seats, off the request that approved the subscription
 * (027 · FR-039 · FR-042).
 *
 * ⚠️ RE-RUNNABLE, NOT REPORT-ONCE. Every step of the Action is idempotent — an
 * existing booking is skipped, a released row is revived by a conditional UPDATE
 * — so a second pass costs a read and takes nothing twice. A duplicate notice is
 * worth far less than a permanent silent loss, which is what a "report once and
 * give up" job produces when the first attempt lands during a queue outage.
 *
 * ⚠️ AND IT NEVER CALLS `WorkspaceContext::set()`. The context is an
 * application-wide singleton that caches its resolution, so a worker that sets it
 * leaks this teacher's workspace into whatever it handles next.
 * `forWorkspace()` puts the previous one back.
 */
class ClaimSubscriptionSeatsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $workspaceId,
        private readonly int $studentUserId,
        private readonly int $courseId,
        private readonly int $cohortId,
        /** The subscription's `effective_ends_on` — what the freeze made of it. */
        private readonly string $windowEnd,
    ) {}

    public function handle(
        ClaimSubscriptionSeats $claim,
        DispatchNotification $dispatch,
        SessionSettings $settings,
        WorkspaceContext $context,
    ): void {
        $workspace = Workspace::query()->withoutGlobalScopes()->find($this->workspaceId);
        $student = User::query()->find($this->studentUserId);

        if ($workspace === null || $student === null) {
            return;
        }

        $result = $context->forWorkspace(
            $workspace,
            fn (): array => $claim->handle(
                $this->workspaceId,
                $student,
                $this->courseId,
                $this->cohortId,
                Carbon::parse($this->windowEnd),
            ),
        );

        if ($result['refused'] === []) {
            return;
        }

        $this->announce($result['refused'], $student, $workspace, $dispatch, $settings);
    }

    /**
     * ⚠️ ONE NOTICE NAMING EVERY SESSION, NEVER ONE PER SESSION. A month of a
     * group is a dozen lessons; a message each is a dozen buzzes on a phone from
     * one approval, and the predictable result is a muted channel — taking the
     * absence alert with it.
     *
     * @param  list<array{uuid: string, title: string, starts_at: string, reason: string}>  $refused
     */
    private function announce(
        array $refused,
        User $student,
        Workspace $workspace,
        DispatchNotification $dispatch,
        SessionSettings $settings,
    ): void {
        $lines = implode('، ', array_map(
            fn (array $row): string => sprintf(
                '%s (%s)',
                $row['title'],
                Carbon::parse($row['starts_at'])->setTimezone($settings->timezone())->format('Y-m-d H:i'),
            ),
            $refused,
        ));

        $dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::SubscriptionSeatUnavailable,
            variables: ['student_name' => $student->name, 'sessions' => $lines],
            actionUrl: '/schedule',
            subject: $student,
            workspaceId: $this->workspaceId,
        ));

        $teacher = $workspace->owner;

        if ($teacher === null) {
            return;
        }

        // The teacher's copy, because they are the only one who can widen the
        // capacity — FR-042 calls it «عملاً ناقصاً» that must not be swallowed.
        // Their link is the management screen, not the student's timetable.
        $dispatch->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::SubscriptionSeatUnavailable,
            variables: ['student_name' => $student->name, 'sessions' => $lines],
            actionUrl: '/manage/sessions',
            subject: $student,
            workspaceId: $this->workspaceId,
        ));
    }
}
