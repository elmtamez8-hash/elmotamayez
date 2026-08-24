<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Events\NotificationQueued;
use App\Modules\Notifications\Events\NotificationRequested;
use App\Modules\Notifications\Exceptions\PermanentDeliveryException;
use App\Modules\Notifications\Jobs\DeliverNotificationJob;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Notifications\Support\PreferenceResolver;
use App\Modules\Notifications\Support\QuietHours;
use App\Modules\Notifications\Support\RecipientResolver;
use App\Modules\Notifications\Support\TemplateRenderer;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The single entrance for every notification in the product.
 *
 * Shape of one dispatch:
 *
 *   resolve recipients  →  for each: resolve channels  →  render  →  ONE record
 *   →  one delivery row per channel  →  one queued job per channel  →  return
 *
 * Three properties come out of that ordering and each is a requirement:
 *
 *  - the record is written once, before any channel is consulted, so a message
 *    on three channels is still one line in the feed (FR-007).
 *  - each channel gets its OWN job, so one channel failing cannot take the
 *    others down with it (FR-006). A single job for all channels would.
 *  - nothing is delivered inline, so a slow provider cannot slow the operation
 *    that triggered it (FR-008 · SC-005).
 *
 * @see DeliverNotificationJob
 */
class DispatchNotification extends Action
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly PreferenceResolver $preferences,
        private readonly TemplateRenderer $renderer,
        private readonly QuietHours $quietHours,
        private readonly ChannelRegistry $registry,
    ) {}

    /**
     * @return Collection<int, Notification>
     */
    public function handle(NotificationRequest $request): Collection
    {
        $recipients = $this->recipients->resolve($request->recipient, $request->type, $request->subject);

        /** @var Collection<int, Notification> $created */
        $created = collect();

        foreach ($recipients as $recipient) {
            $notification = $this->dispatchTo($recipient, $request);

            if ($notification !== null) {
                $created->push($notification);
            }
        }

        return $created;
    }

    private function dispatchTo(User $recipient, NotificationRequest $request): ?Notification
    {
        $channels = $this->preferences->channelsFor($recipient, $request->type);

        try {
            // Rendered once per recipient, from the in-app template. A recipient
            // with no channels at all still gets the record: the feed is where a
            // notification lives, and an empty channel list means "do not push
            // it at me", not "pretend it never happened".
            $rendered = $this->renderer->render(
                $request->type,
                NotificationChannel::InApp,
                $request->variables,
            );
        } catch (PermanentDeliveryException $e) {
            // A missing or malformed template is a deployment fault, not a reason
            // to fail the enrollment/payment/exam that triggered this.
            Log::error('[notifications] template render failed', [
                'type' => $request->type->value,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $notification = Notification::query()->create([
            'recipient_user_id' => $recipient->getKey(),
            'workspace_id' => $request->workspaceId,
            'type' => $request->type->value,
            'subject_user_id' => $request->subject?->getKey(),
            'source_type' => $request->sourceType,
            'source_id' => $request->sourceId,
            'payload' => $request->variables,
            'title_ar' => $rendered->titleAr,
            'body_ar' => $rendered->bodyAr,
            'action_url' => $request->actionUrl,
        ]);

        NotificationRequested::dispatch($notification);

        foreach ($channels as $channel) {
            $this->queue($notification, $channel, $request->type, $rendered->templateId, $recipient);
        }

        return $notification;
    }

    private function queue(
        Notification $notification,
        NotificationChannel $channel,
        NotificationType $type,
        int $templateId,
        User $recipient,
    ): void {
        $deferUntil = $this->quietHours->deferUntil($recipient, $type, $channel);
        $enabled = $this->registry->get($channel)->isEnabled();

        if ($enabled && $this->foldIntoOpenDigest($notification, $recipient, $channel, $type, $templateId)) {
            return;
        }

        $deferUntil = $this->applyDigestWindow($recipient, $type, $channel, $deferUntil);

        $delivery = NotificationDelivery::query()->create([
            'notification_id' => $notification->getKey(),
            'channel' => $channel->value,
            'template_id' => $templateId,
            'status' => $enabled ? DeliveryStatus::Queued->value : DeliveryStatus::Skipped->value,
            'failure_reason' => $enabled ? null : 'القناة معطَّلة على مستوى المنصة.',
            'deferred_until' => $enabled ? $deferUntil : null,
        ]);

        if (! $enabled) {
            // Recorded and not queued. Filtering it out silently would be cheaper,
            // but the delivery log is where an operator looks when a message did
            // not arrive, and "no row at all" is the one answer that explains
            // nothing.
            return;
        }

        $job = DeliverNotificationJob::dispatch($delivery->getKey())->onQueue($type->queue());

        if ($deferUntil !== null) {
            // The queue itself holds the delay, so a deferred message cannot be
            // dropped by a sweeper that never runs (FR-033).
            $job->delay($deferUntil);
        }

        NotificationQueued::dispatch($delivery);
    }

    /**
     * Twenty attendance alerts in an hour should arrive as one message, not
     * twenty (FR-034).
     *
     * The first one in the window becomes the carrier and is deferred to the end
     * of it; anything of the same type on the same channel that follows is folded
     * into it — recorded, so the admin log shows what happened, but not sent
     * separately. The feed still lists every notification: digesting is about how
     * loudly a channel pushes, not about hiding what occurred.
     *
     * Never applies to mandatory types (FR-035) or to in-app, both of which come
     * back null from the digest window.
     *
     * ponytail: the carrier sends its own wording, not a generated summary of the
     * batch. Enough to stop the flood, which is the requirement; a rendered
     * "3 alerts" summary can come with the first channel that shows one.
     */
    private function foldIntoOpenDigest(
        Notification $notification,
        User $recipient,
        NotificationChannel $channel,
        NotificationType $type,
        int $templateId,
    ): bool {
        if ($this->digestWindow($recipient, $type, $channel) === null) {
            return false;
        }

        $open = NotificationDelivery::query()
            ->where('channel', $channel->value)
            ->where('status', DeliveryStatus::Queued->value)
            ->where('deferred_until', '>', now())
            ->whereHas(
                'notification',
                fn ($query) => $query
                    ->where('recipient_user_id', $recipient->getKey())
                    ->where('type', $type->value),
            )
            ->exists();

        if (! $open) {
            return false;
        }

        NotificationDelivery::query()->create([
            'notification_id' => $notification->getKey(),
            'channel' => $channel->value,
            'template_id' => $templateId,
            'status' => DeliveryStatus::Skipped->value,
            'failure_reason' => 'مجمَّع مع رسالة سابقة من النوع نفسه.',
        ]);

        return true;
    }

    private function applyDigestWindow(
        User $recipient,
        NotificationType $type,
        NotificationChannel $channel,
        ?CarbonImmutable $deferUntil,
    ): ?CarbonImmutable {
        $window = $this->digestWindow($recipient, $type, $channel);

        if ($window === null) {
            return $deferUntil;
        }

        $digestUntil = CarbonImmutable::now()->addMinutes($window);

        // Quiet hours win when they reach further: a digest must not push a
        // message back into the middle of the night.
        return $deferUntil !== null && $deferUntil->greaterThan($digestUntil) ? $deferUntil : $digestUntil;
    }

    private function digestWindow(User $recipient, NotificationType $type, NotificationChannel $channel): ?int
    {
        if (! $channel->isExternal()) {
            return null;
        }

        return $this->preferences->digestWindowFor($recipient, $type);
    }
}
