<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Data\NotificationEnvelope;
use App\Modules\Notifications\Exceptions\PermanentDeliveryException;
use App\Modules\Notifications\Support\NotificationChannel;

/**
 * Abstraction over a delivery channel (in-app, WhatsApp, email, Telegram, SMS,
 * push).
 *
 * A new channel implements this interface and gets tagged 'notification.channels'
 * in NotificationsServiceProvider::register(). Nothing else changes: no listener,
 * no action, no notification type. That is the addendum's constraint — "no
 * business logic may depend on a specific notification provider" — expressed as
 * a type rather than as a review comment.
 *
 * Modelled on Payments\Contracts\PaymentProviderInterface, which has held the
 * same line for payment providers in this codebase.
 */
interface NotificationChannelInterface
{
    /**
     * Which channel this implements. One class per enum case.
     */
    public function channel(): NotificationChannel;

    /**
     * Whether the channel can run at all right now: credentials configured, the
     * provider not deliberately switched off.
     *
     * A channel answering false is recorded as skipped, never failed — being
     * unconfigured is a state of the deployment, not an incident.
     */
    public function isEnabled(): bool;

    /**
     * Whether this particular recipient is reachable here — a verified phone for
     * WhatsApp, a verified address for email.
     *
     * Kept separate from isEnabled() because the two answer different questions.
     * Merged, a user with no phone number would read as an outage in WhatsApp.
     */
    public function canReach(NotificationEnvelope $envelope): bool;

    /**
     * Deliver, or throw.
     *
     * Returns void rather than a result object because the channel does not own
     * the delivery record: the job writes status, so a channel cannot report
     * "delivered" for something the job saw fail. It also keeps channels testable
     * without a database.
     *
     * @throws PermanentDeliveryException retrying will not fix it — an invalid
     *                                    number, an unapproved template, a
     *                                    recipient who blocked the sender. Not
     *                                    retried.
     * @throws \Throwable anything else counts as transient and
     *                    is retried with escalating backoff.
     */
    public function send(NotificationEnvelope $envelope): void;
}
