<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Models\User;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Data\NotificationEnvelope;
use App\Modules\Notifications\Events\NotificationDelivered;
use App\Modules\Notifications\Events\NotificationFailed;
use App\Modules\Notifications\Exceptions\PermanentDeliveryException;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Contracts\GuardianDirectory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Delivers one notification on one channel.
 *
 * One job per channel, deliberately. Laravel's own notify() queues a single job
 * for every channel a notification uses, which means the second channel never
 * runs once the first throws — the exact coupling FR-006 forbids.
 *
 * Carries a delivery id, not the model: the row's status changes between queueing
 * and running (it may be revoked, retried, or already handled), and a serialized
 * model would run against a stale copy of it.
 *
 * WorkspaceContext is never set here (NFR-008). One worker handles deliveries for
 * users from many workspaces in sequence, and the context is an application-wide
 * singleton that caches its answer, so setting it leaks into the next job.
 */
class DeliverNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        private readonly int $deliveryId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        /** @var list<int> */
        return config('notifications.backoff_seconds', [30, 120, 300, 900]);
    }

    public function handle(ChannelRegistry $registry, GuardianDirectory $guardians): void
    {
        $delivery = NotificationDelivery::query()->with('notification.recipient')->find($this->deliveryId);

        if ($delivery === null || $delivery->status()->isTerminal()) {
            return;
        }

        $notification = $delivery->notification;
        $recipient = $notification->recipient;
        $type = $notification->type();

        if (! $this->stillAuthorised($guardians, $notification, $recipient, $type)) {
            // The relation was revoked while this sat in the queue. Stopping
            // "immediately" (FR-023) has to include work already in flight,
            // otherwise a revoked guardian keeps receiving for as long as the
            // backlog lasts.
            $delivery->markSkipped('أُلغيت العلاقة قبل التسليم.');

            return;
        }

        if (! $registry->has($delivery->channel())) {
            $delivery->markSkipped('القناة غير مُنفَّذة.');

            return;
        }

        $channel = $registry->get($delivery->channel());

        if (! $channel->isEnabled()) {
            $delivery->markSkipped('القناة معطَّلة على مستوى المنصة.');

            return;
        }

        $envelope = new NotificationEnvelope(
            recipient: $recipient,
            type: $type,
            title: $notification->title,
            body: $notification->body,
            actionUrl: $notification->action_url,
            payload: $notification->payload ?? [],
            notificationUuid: $notification->uuid,
        );

        if (! $channel->canReach($envelope)) {
            $delivery->markSkipped('لا توجد وسيلة تواصل مُتحقَّق منها لهذه القناة.');

            return;
        }

        $delivery->increment('attempts');

        try {
            $channel->send($envelope);
        } catch (PermanentDeliveryException $e) {
            $delivery->markFailed($e->getMessage());
            NotificationFailed::dispatch($delivery, $e->getMessage(), true);

            // Swallowed on purpose: rethrowing would put the job back on the queue
            // for a failure that repeating cannot change.
            return;
        } catch (Throwable $e) {
            if ($this->attempts() >= $this->tries) {
                $delivery->markFailed($e->getMessage());
                NotificationFailed::dispatch($delivery, $e->getMessage(), false);

                return;
            }

            throw $e;
        }

        $delivery->markDelivered();
        NotificationDelivered::dispatch($delivery);
    }

    /**
     * A guardian's authorisation is re-checked at send time, not just at dispatch.
     * The recipient being the subject means this is the student's own message,
     * which no relation gates.
     */
    private function stillAuthorised(
        GuardianDirectory $guardians,
        Notification $notification,
        User $recipient,
        NotificationType $type,
    ): bool {
        $permission = $type->requiredGuardianPermission();

        if ($permission === null || $notification->subject_user_id === null) {
            return true;
        }

        if ((int) $notification->subject_user_id === (int) $recipient->getKey()) {
            return true;
        }

        $subject = $notification->subject;

        return $subject !== null && $guardians->isAuthorised($recipient, $subject, $permission);
    }
}
