<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Modules\Notifications\Actions\SavePushSubscription;
use App\Modules\Notifications\Contracts\NotificationChannelInterface;
use App\Modules\Notifications\Data\NotificationEnvelope;
use App\Modules\Notifications\Exceptions\PermanentDeliveryException;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Support\NotificationChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;
use Throwable;

/**
 * The second channel that leaves the platform (spec 012 · US2).
 *
 * ⚠️ `Push` IS DELIBERATELY ABSENT FROM `NotificationType::defaultChannels()`,
 * AND SUBSCRIBING IS WHAT SWITCHES IT ON. Three reasons, each read out of a named
 * file rather than assumed:
 *
 *  - `DispatchNotification::queue()` writes a delivery row and dispatches a job
 *    from `isEnabled()` alone; `canReach()` is not asked until the job runs. In
 *    the defaults that is one row and one job per notification per recipient on
 *    the whole platform, and the large majority of recipients have no device
 *    registered at all.
 *  - `WhatsAppDefaultsTest` asserts `SecurityAlert->defaultChannels()` with
 *    `toBe()`, an exact list. Adding a case breaks it — correctly: that test is
 *    the guard that says a channel class alone must deliver nothing.
 *  - `PreferenceResolver` merges the DEFAULTS ON TOP of a stored preference for a
 *    mandatory type. In the defaults, push would become a channel nobody can
 *    switch off, on a device they may be sharing.
 *
 * The way out is `PreferenceResolver`'s own line `$chosen = $stored ?? $type->
 * defaultChannels()`: a stored row REPLACES the defaults. So
 * {@see SavePushSubscription} writes those rows
 * for the three subjects a push is for, and a person who never subscribed costs
 * nothing.
 *
 * ⚠️ THE PAYLOAD IS A TITLE AND A LINK, NEVER THE MESSAGE. The push protocol has
 * no revocation but `410`, and a device may be shared: a `security_alert` or a
 * child's absence report rendered on a lock screen is read by whoever is standing
 * next to it. The body lives behind the link, where a sign-in guards it.
 *
 * ⚠️ AND THE CLIENT IS BUILT LAZILY, NOT INJECTED INTO THE CONSTRUCTOR.
 * `ChannelRegistry` instantiates every tagged channel when the registry is built,
 * and `WebPush`'s constructor validates the VAPID pair and hunts for a PSR-18
 * client — both of which throw on a deployment (or a test) that has no keys. A
 * channel that cannot be constructed is a channel that takes the whole
 * notification system down with it.
 */
class WebPushChannel implements NotificationChannelInterface
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::Push;
    }

    /**
     * ⚠️ ALL THREE VAPID VALUES, `subject` INCLUDED. It is the `sub` claim of the
     * signed JWT, not decoration — a push service is entitled to refuse a token
     * without one, and «configured» has to mean the same thing here as it does at
     * the far end. Unconfigured is recorded as SKIPPED, never failed: it is a
     * state of the deployment, not an incident.
     */
    public function isEnabled(): bool
    {
        foreach (['public', 'private', 'subject'] as $key) {
            $value = config("webpush.vapid.{$key}");

            if (! is_string($value) || $value === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * FR-035 with no line of its own: a person who never granted permission has no
     * subscription, so this answers false and the delivery is skipped. Refusing
     * the browser prompt disables nothing else.
     */
    public function canReach(NotificationEnvelope $envelope): bool
    {
        return $this->subscriptionsFor($envelope)->isNotEmpty();
    }

    public function send(NotificationEnvelope $envelope): void
    {
        $rows = $this->subscriptionsFor($envelope);

        if ($rows->isEmpty()) {
            // Reached only if canReach() and send() disagree — the last device was
            // forgotten between the two. Refused rather than guessed, as WhatsApp
            // refuses a number that vanished.
            throw PermanentDeliveryException::invalidRecipient('لا يوجد جهاز مشترك في الإشعارات الفوريّة لهذا الحساب.');
        }

        $payload = json_encode([
            'title' => $envelope->titleAr,
            'url' => $envelope->actionUrl ?? '/notifications',
            // The bell reads this to mark the row read when the person taps.
            'uuid' => $envelope->notificationUuid,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $client = app(WebPush::class);
        $accepted = 0;
        $lastFailure = null;

        foreach ($rows as $row) {
            try {
                $report = $client->sendOneNotification($this->subscription($row), $payload);
            } catch (Throwable $e) {
                // One unreachable device must not cost the others theirs.
                $lastFailure = $e->getMessage();

                continue;
            }

            if ($report->isSuccess()) {
                $accepted++;
                $row->forceFill(['last_used_at' => now()])->save();

                continue;
            }

            /*
            | ⚠️ EXPIRED IS `404` AS WELL AS `410`, AND THE LIBRARY OWNS THE LIST.
            | `isSubscriptionExpired()` covers both; a literal `=== 410` here would
            | leave every 404'd row in the table for ever, retried on every
            | notification until the retention sweep took it two years later.
            |
            | This is the ONLY revocation the protocol has, which is the other half
            | of why the payload carries no message text.
            */
            if ($report->isSubscriptionExpired()) {
                $row->delete();

                continue;
            }

            $lastFailure = $report->getReason();
        }

        if ($accepted > 0) {
            return;
        }

        // ⚠️ MASKED: an endpoint is a device identifier, and a delivery log is the
        // one place everybody forwards to a monitoring vendor (013 · FR-041).
        Log::warning('[notifications] web push send failed', [
            'devices' => $rows->count(),
            'reason' => $lastFailure ?? 'unknown',
        ]);

        // Transient by default — every permanent case above deleted its own row,
        // so what is left is a service that was busy or unreachable.
        throw new RuntimeException($lastFailure ?? 'تعذّر إرسال الإشعار الفوري.');
    }

    /** @return Collection<int, PushSubscription> */
    private function subscriptionsFor(NotificationEnvelope $envelope): Collection
    {
        return PushSubscription::query()
            ->where('user_id', $envelope->recipient->getKey())
            ->get();
    }

    private function subscription(PushSubscription $row): Subscription
    {
        return new Subscription(
            endpoint: $row->endpoint,
            publicKey: $row->p256dh,
            authToken: $row->auth,
            // RFC 8291. The library still defaults to the pre-standard `aesgcm`
            // for backwards compatibility; naming it is what stops a browser
            // refusing a body it cannot decrypt.
            contentEncoding: ContentEncoding::aes128gcm,
        );
    }
}
