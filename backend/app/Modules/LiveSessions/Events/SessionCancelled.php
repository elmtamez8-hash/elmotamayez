<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\ClassSession;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A session will not happen — cancelled outright, or suspended by a freeze.
 *
 * Carries the reason because the notification has to explain itself: "your
 * session is off" without a why is the message that generates the support
 * ticket it was meant to prevent.
 *
 * And it carries WHO held a seat, because by the time a listener runs, nobody
 * does. Releasing the seats is part of cancelling, so a listener reading the
 * bookings afterwards sees one undifferentiated pile of released rows and tells
 * the student who cancelled last month that their session is off. The Action
 * knows the answer at the moment it is still true; a listener can only guess at
 * it from a `cancellation_reason` string.
 *
 * @param  list<int>  $seatHolderIds
 */
class SessionCancelled
{
    use Dispatchable;

    /** @param list<int> $seatHolderIds */
    public function __construct(
        public readonly ClassSession $session,
        public readonly ?string $reason = null,
        public readonly array $seatHolderIds = [],
    ) {}
}
