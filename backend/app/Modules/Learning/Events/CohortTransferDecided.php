<?php

declare(strict_types=1);

namespace App\Modules\Learning\Events;

use App\Modules\Learning\Models\CohortTransferRequest;

/**
 * The teacher answered (FR-028ح, the student's half).
 *
 * ⚠️ ONE EVENT WITH A FLAG, WHICH THIS REPOSITORY USUALLY REFUSES — and the
 * reason it is right here is that the flag cannot be ignored. `SessionDelivered`
 * is a separate event from `SessionCompleted` because a listener could quietly
 * treat one as the other and still write plausible rows; here the listener's
 * whole job is to pick between two notification types, so a listener that
 * dropped the flag would not compile into anything that sends a message at all.
 *
 * The status is on the row as well, but it is read off the event: the row is
 * refreshed inside the deciding transaction, and a listener that re-read it
 * would be reading it again from the queue for no gain.
 */
class CohortTransferDecided
{
    public function __construct(
        public readonly CohortTransferRequest $request,
        public readonly bool $approved,
    ) {}
}
