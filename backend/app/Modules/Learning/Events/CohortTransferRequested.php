<?php

declare(strict_types=1);

namespace App\Modules\Learning\Events;

use App\Modules\Learning\Models\CohortTransferRequest;

/**
 * A student asked to move (FR-028ح, the teacher's half).
 *
 * An event rather than a call into Notifications: Learning names a recipient and
 * a fact, and what reaches whom over which channel is the other module's
 * decision. It carries the model because every listener needs the same four
 * names off it, and re-fetching by id inside each one is a query per listener
 * for a row we are holding.
 */
class CohortTransferRequested
{
    public function __construct(
        public readonly CohortTransferRequest $request,
    ) {}
}
