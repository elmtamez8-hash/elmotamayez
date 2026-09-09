<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Listeners;

use App\Models\User;
use App\Modules\LiveSessions\Actions\ClaimSubscriptionSeats;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Events\SessionsAssignedToCohort;
use App\Modules\LiveSessions\Events\SessionScheduled;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\SubscriptionDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;

/**
 * A session the teacher just put on the calendar is booked for every active
 * member of the group it belongs to (027 · FR-040 · 052).
 *
 * ⚠️ THE NAME PREDATES 052 AND IS KEPT DELIBERATELY. It used to seat SUBSCRIBERS
 * only; the subscription is now the choice of door and not the guest list, so
 * the class seats members. Renaming it would churn the provider wiring and every
 * test that names it for no change in behaviour — `media.bunny.source_disk` is
 * the precedent for keeping a name and writing down what it now means.
 *
 * ⚠️ BOOKING IS A CONTINUING BEHAVIOUR, NOT A SWEEP RUN ONCE AT ACTIVATION.
 * Without this, next week's lesson — created tomorrow — passes the subscriber by
 * in silence, and they are exactly the person who has already paid for it.
 *
 * ⚠️ TWO EVENTS, BECAUSE THERE ARE TWO WAYS A SESSION JOINS A GROUP. Creating it
 * with a `cohort_id` fires `SessionScheduled`; attaching an existing one fires
 * `SessionsAssignedToCohort` — a bulk `update()` that fires no model events at
 * all. Listening to the first alone is a feature that works on one path and is
 * quiet on the other.
 *
 * ⚠️ AND THE DIRECTION IS GROUP → SUBSCRIBERS, NEVER SUBSCRIBERS → SESSION.
 * Starting from «who holds a live subscription for this course» books Saturday's
 * subscriber into Sunday's lesson — the thing all of 021 exists to prevent — and
 * a workspace-wide plan would seat somebody who is in no group of this course at
 * all. The group's own membership is the list; the subscription only filters it.
 *
 * ⚠️ AND THE PER-SEAT DECISION IS NOT REPEATED HERE. `ClaimSubscriptionSeats`
 * owns it (the status table, the frozen-count skip, the revival of a released
 * row); this class only enumerates in the other direction.
 */
class BookSubscribersOnScheduled implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    public function __construct(
        private readonly ClaimSubscriptionSeats $claim,
        private readonly CohortDirectory $cohorts,
        private readonly SubscriptionDirectory $subscriptions,
        private readonly DispatchNotification $dispatch,
        private readonly SessionSettings $settings,
        private readonly WorkspaceContext $context,
    ) {}

    public function handleScheduled(SessionScheduled $event): void
    {
        $session = $event->session;

        if ($session->cohort_id === null || $session->course_id === null) {
            return;
        }

        $this->run(
            (int) $session->workspace_id,
            (int) $session->course_id,
            (int) $session->cohort_id,
            [$session],
        );
    }

    public function handleAssigned(SessionsAssignedToCohort $event): void
    {
        $sessions = ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereIn('id', $event->sessionIds)
            ->where('status', ClassSessionStatus::Scheduled->value)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        if ($sessions->isEmpty()) {
            return;
        }

        $this->run($event->workspaceId, $event->courseId, $event->cohortId, array_values($sessions->all()));
    }

    /**
     * @param  list<ClassSession>  $sessions
     */
    private function run(int $workspaceId, int $courseId, int $cohortId, array $sessions): void
    {
        $memberIds = $this->cohorts->activeMemberIdsFor($cohortId);

        if ($memberIds === [] || $sessions === []) {
            return;
        }

        $workspace = Workspace::query()->withoutGlobalScopes()->find($workspaceId);

        if ($workspace === null) {
            return;
        }

        $this->context->forWorkspace($workspace, function () use ($workspaceId, $courseId, $sessions, $memberIds, $workspace): void {
            /** @var array<int, list<string>> $refusedBy student id => session labels */
            $refusedBy = [];
            /** @var array<int, User> $students */
            $students = [];

            foreach ($sessions as $session) {
                /*
                | Asked per session rather than once, because the answer is
                | «whose subscription is live AT THIS MOMENT» — a batch assignment
                | can span the end of somebody's month, and seating them in a
                | lesson their subscription no longer reaches is the seat FR-045
                | exists to take away again.
                |
                | ⚠️ AND SINCE 052 IT CHOOSES THE DOOR RATHER THAN THE AUDIENCE.
                | An empty answer used to end the session's turn — so a group
                | whose students pay by credit, which is most of them, was booked
                | into nothing at all and every member had to find each lesson and
                | press «احجز» by hand. The list is the GROUP's membership now;
                | the subscription only says which of `claimOne` /
                | `claimOneAsMember` a given member goes through.
                */
                $subscriberIds = $this->subscriptions->subscriberIdsAmong(
                    $memberIds,
                    $courseId,
                    $session->type->value,
                    $session->starts_at,
                );

                $isSubscriber = array_flip($subscriberIds);

                $existing = SessionBooking::query()
                    ->withoutWorkspaceScope()
                    ->where('class_session_id', $session->getKey())
                    ->whereIn('student_user_id', $memberIds)
                    ->get()
                    ->keyBy('student_user_id');

                foreach ($memberIds as $studentId) {
                    $student = $students[$studentId] ??= User::query()->find($studentId);

                    if ($student === null) {
                        continue;
                    }

                    $subscribed = isset($isSubscriber[$studentId]);

                    $refusal = $subscribed
                        ? $this->claim->claimOne($session, $student, $existing->get($studentId))
                        : $this->claim->claimOneAsMember($session, $student, $existing->get($studentId));

                    if ($refusal === null || $refusal === '') {
                        continue;
                    }

                    /*
                    | ⚠️ ONLY A SUBSCRIBER'S REFUSAL IS ANNOUNCED, AND THE REASON
                    | IS WHAT WAS PROMISED. A month that was paid for and then
                    | could not be seated is news the student must have. A member
                    | paying by credit was promised nothing by this pass — the
                    | commonest refusals they meet are their own unfinished
                    | homework and their own balance, both of which their «احجز»
                    | button already says to their face — so «تعذّر حجز مقعدك»
                    | fired at every one of them on every schedule the teacher
                    | publishes is a channel that gets muted, taking the absence
                    | alert with it. The type is `SubscriptionSeatUnavailable`
                    | besides, and it names a thing they do not hold.
                    */
                    if ($subscribed) {
                        $refusedBy[$studentId][] = $this->label($session);
                    }
                }
            }

            $this->announce($refusedBy, $students, $workspace, $workspaceId);
        });
    }

    private function label(ClassSession $session): string
    {
        return sprintf(
            '%s (%s)',
            $session->title,
            Carbon::parse($session->starts_at)->setTimezone($this->settings->timezone())->format('Y-m-d H:i'),
        );
    }

    /**
     * ⚠️ ONE NOTICE PER OPERATION, NOT ONE PER SESSION (FR-042 · T050). A term of
     * lessons assigned in one press is one event carrying many sessions, so a
     * per-session message would be a dozen buzzes on every subscriber's phone
     * from a single click — and a family that mutes the channel over it loses the
     * absence alert with it.
     *
     * @param  array<int, list<string>>  $refusedBy
     * @param  array<int, User|null>  $students
     */
    private function announce(array $refusedBy, array $students, Workspace $workspace, int $workspaceId): void
    {
        if ($refusedBy === []) {
            return;
        }

        $names = [];

        foreach ($refusedBy as $studentId => $labels) {
            $student = $students[$studentId] ?? null;

            if ($student === null) {
                continue;
            }

            $names[] = $student->name;

            $this->dispatch->handle(new NotificationRequest(
                recipient: $student,
                type: NotificationType::SubscriptionSeatUnavailable,
                variables: ['student_name' => $student->name, 'sessions' => implode('، ', $labels)],
                actionUrl: '/schedule',
                subject: $student,
                workspaceId: $workspaceId,
            ));
        }

        $teacher = $workspace->owner;

        if ($teacher === null || $names === []) {
            return;
        }

        // The teacher's single copy names everybody, because the answer to all of
        // them is the same one action: widen the capacity or add a session.
        $this->dispatch->handle(new NotificationRequest(
            recipient: $teacher,
            type: NotificationType::SubscriptionSeatUnavailable,
            variables: [
                'student_name' => implode('، ', $names),
                'sessions' => implode('، ', array_unique(array_merge(...array_values($refusedBy)))),
            ],
            actionUrl: '/manage/sessions',
            subject: $teacher,
            workspaceId: $workspaceId,
        ));
    }
}
