<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Exceptions\PermanentDeliveryException;

/**
 * A channel that can carry a one-time code to a contact detail nobody has proven
 * yet (spec 020, FR-015).
 *
 * ⚠️ THIS EXISTS BECAUSE THE ORDINARY PATH CANNOT DO IT, AND THE REASON IS
 * CIRCULAR BY CONSTRUCTION. {@see NotificationChannelInterface::canReach()} asks
 * whether the recipient has a VERIFIED contact detail — and the verification code
 * is, by definition, the message that goes to an UNVERIFIED one. Sent through
 * DispatchNotification it would be skipped every time, for ever, and no number on
 * the platform could ever be verified: the channel would be built, green, and
 * decorative.
 *
 * A capability declared rather than an `instanceof` in the controller, the same
 * shape Media's ProviderCapabilities uses: the caller asks what a channel can do
 * instead of asking which vendor it is. Email and SMS will implement this too;
 * the in-app channel never will, and that is the honest answer — a code delivered
 * inside the account is proof of nothing.
 */
interface SendsVerificationCodes
{
    /**
     * @param  string  $toE164  already normalised — this method does not clean up
     *                          input, because the column it came from must not
     *                          hold two shapes of the same number.
     *
     * @throws PermanentDeliveryException the address is unusable or the template
     *                                    is not approved; retrying changes
     *                                    nothing and the user is waiting.
     * @throws \Throwable transient — the caller decides whether to surface it.
     */
    public function sendVerificationCode(string $toE164, string $code): void;
}
