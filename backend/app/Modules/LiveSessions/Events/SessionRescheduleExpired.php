<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A postponement nobody answered before its moment passed.
 *
 * ⚠️ DISPATCHED ONLY BEHIND THE SETTLE THAT WON. `ExpireSessionRescheduleRequestsJob`
 * fires this when `PendingRescheduleRequest::settle()` returns `true`, so a
 * second sweep over an already-expired row — or a teacher's answer that reached
 * the row a second earlier — says nothing. The same shape as
 * `PrivateSessionExpired`, for the same reason: the guard is the conditional
 * UPDATE, and a predicate in the listener would be a second answer to it.
 */
class SessionRescheduleExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly SessionRescheduleRequest $request) {}
}
