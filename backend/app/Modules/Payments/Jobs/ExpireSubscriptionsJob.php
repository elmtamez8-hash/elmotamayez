<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Enums\SubscriptionStatus;
use App\Modules\Payments\Models\Subscription;
use App\Modules\Payments\Support\SubscriptionAccess;
use App\Modules\Tenancy\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The clock runs out, and the warning before it (T096 · FR-027).
 *
 * Two passes, one job, because they read the same column from opposite sides and
 * splitting them would put the same date arithmetic in two files.
 *
 * ⚠️ EVERY COMPARISON IS AGAINST A BARE DATE STRING IN THE `<` DIRECTION, NEVER
 * `<=`. `effective_ends_on` is a DATE holding `2026-09-30 00:00:00` on SQLite —
 * Eloquent writes a date-cast attribute through the model's datetime format —
 * so `<= '2026-09-30'` is FALSE for a subscription ending that day, while on
 * MySQL it is TRUE. Written the obvious way, this job expires everything a day
 * late on one engine and on time on the other, and no local test can see it.
 *
 * ⚠️ `chunkById`, NEVER `chunk`. The predicate SHRINKS under the walk — this job
 * is what moves rows out of `status = active` — so OFFSET paging skips as many
 * rows per page as the previous page fixed, and reports success. A subscription
 * missed that way is access that never stops.
 *
 * ⚠️ AND THE NOTICE IS STAMPED BEFORE IT IS SENT. The dispatch is queued, so a
 * worker dying between the two would otherwise leave the row eligible again
 * tomorrow — and the two orderings do not cost the same: one lost reminder
 * against one reminder every night until the subscription ends.
 *
 * ⚠️ EXPIRY CLOSES THE ENROLMENTS THE SUBSCRIPTION OPENED, AND ONLY THOSE.
 * `expires_at` gates nothing in this tree (nothing reads it), so an `active`
 * enrolment left behind is permanent access bought for a month. The rows are
 * found by `(order_id, source = 'subscription')` — which is exactly why
 * `ActivateSubscription` stamps neither marker on an enrolment the student
 * already had: a course they bought outright must survive their subscription
 * ending.
 */
class ExpireSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(DispatchNotification $notifications): void
    {
        $today = CarbonImmutable::today();

        $this->warn($today, $notifications);
        $this->expire($today);
    }

    /**
     * Tell whoever is inside the notice window, once.
     */
    private function warn(CarbonImmutable $today, DispatchNotification $notifications): void
    {
        $days = (int) PlatformSettings::get(
            'subscription.expiring_notice_days',
            (int) config('subscriptions.expiring_notice_days', 3),
        );

        if ($days < 1) {
            return;
        }

        Subscription::query()
            ->withoutWorkspaceScope()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNull('expiring_notified_at')
            // Still running (>= today) and inside the window (< today + n + 1).
            // Both bounds in the safe direction; see the class docblock.
            ->where('effective_ends_on', '>=', $today->toDateString())
            ->where('effective_ends_on', '<', $today->addDays($days + 1)->toDateString())
            ->with(['plan', 'student', 'workspace'])
            ->chunkById(200, function (iterable $subscriptions) use ($notifications): void {
                foreach ($subscriptions as $subscription) {
                    $this->notifyOne($subscription, $notifications);
                }
            });
    }

    private function notifyOne(Subscription $subscription, DispatchNotification $notifications): void
    {
        $student = $subscription->student;
        $plan = $subscription->plan;
        $workspace = $subscription->workspace;

        /*
        | ⚠️ SILENCE RATHER THAN A GAP. `TemplateRenderer` refuses a message with
        | a missing variable (003 · FR-037), so a fallback like «اشتراك» here
        | would not be a courtesy — it would be a real sentence naming nothing,
        | sent to a student who then cannot tell which teacher it is about.
        |
        | All three are cascade-deleted foreign keys and a plan cannot be deleted
        | at all (`PlanPolicy::delete()` refuses), so this branch is about a row
        | somebody removed by hand. The subscription is still expired by the pass
        | below; only the notice is skipped.
        */
        if ($student === null || $plan === null || $workspace === null) {
            return;
        }

        /*
        | ⚠️ A CONDITIONAL UPDATE, NOT A SAVE. Two overlapping runs of this job —
        | a manual invocation beside the schedule, or a retry — would both read
        | `expiring_notified_at` as null and both send. The loser affects zero
        | rows and says nothing.
        */
        $claimed = Subscription::query()
            ->withoutWorkspaceScope()
            ->whereKey($subscription->getKey())
            ->whereNull('expiring_notified_at')
            ->update(['expiring_notified_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $notifications->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::SubscriptionExpiring,
            variables: [
                'plan_title' => (string) $plan->title,
                'teacher_name' => (string) $workspace->name,
                // ⚠️ `effective_ends_on`, never `ends_on`. A freeze moves the
                // real date and the student would otherwise be told to renew
                // before a deadline that has already been extended for them.
                'ends_on' => CarbonImmutable::parse($subscription->effective_ends_on)->toDateString(),
            ],
            workspaceId: (int) $subscription->workspace_id,
        ));
    }

    /**
     * Stop everything whose last day has passed.
     */
    private function expire(CarbonImmutable $today): void
    {
        Subscription::query()
            ->withoutWorkspaceScope()
            ->where('status', SubscriptionStatus::Active->value)
            ->where('effective_ends_on', '<', $today->toDateString())
            ->chunkById(200, function (iterable $subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    $claimed = Subscription::query()
                        ->withoutWorkspaceScope()
                        ->whereKey($subscription->getKey())
                        ->where('status', SubscriptionStatus::Active->value)
                        ->update(['status' => SubscriptionStatus::Expired->value]);

                    if ($claimed === 0) {
                        continue;
                    }

                    $this->closeAccess($subscription);
                }
            });
    }

    private function closeAccess(Subscription $subscription): void
    {
        SubscriptionAccess::close($subscription);
    }
}
