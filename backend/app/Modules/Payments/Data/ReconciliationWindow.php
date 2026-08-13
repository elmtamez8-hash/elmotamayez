<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Models\PaymentReconciliationRun;
use App\Shared\Data\DataTransferObject;
use Carbon\CarbonImmutable;

/**
 * The stretch of time one reconciliation pass covers — `[from, to)`.
 *
 * ⚠️ HALF-OPEN, AND THE HALF THAT IS OPEN IS THE DECISION. The next pass starts
 * at exactly this one's `to`, so a range closed at both ends visits the boundary
 * second TWICE and one open at both DROPS it. Neither is forgiven by anything
 * downstream: a payment settled in that second would be reconciled twice or
 * never, and "never" is a student who paid and stayed blocked.
 *
 * ⚠️ AND THE START COMES FROM THE LAST RUN, NEVER FROM THE CLOCK. `now()
 * ->subHour()` looks equivalent and is not: a pass that was late by two hours
 * would silently skip the hour before it, and a platform whose worker was down
 * overnight would reconcile the last hour and call the night resolved. The
 * lookback below is the FIRST run's window and nothing else's.
 */
final class ReconciliationWindow extends DataTransferObject
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    /**
     * The window that follows a previous run, or the first one.
     *
     * @param  int  $lookbackMinutes  how far back a platform with no previous run
     *                                reaches. Only ever used once in the lifetime
     *                                of a deployment.
     */
    public static function following(
        ?PaymentReconciliationRun $previous,
        CarbonImmutable $now,
        int $lookbackMinutes = 60,
    ): self {
        $from = $previous === null
            ? $now->subMinutes($lookbackMinutes)
            : CarbonImmutable::instance($previous->window_to);

        // A clock that went backwards, or a previous run recorded ahead of this
        // one. An empty window is the honest answer — a `from` after `to` would
        // be handed to a provider as a range meaning nothing, and providers
        // differ on what they do with one.
        return new self($from->greaterThan($now) ? $now : $from, $now);
    }

    /** `from <= $at < to` — inclusive start, exclusive end. */
    public function contains(CarbonImmutable $at): bool
    {
        return $at->greaterThanOrEqualTo($this->from) && $at->lessThan($this->to);
    }

    public function isEmpty(): bool
    {
        return ! $this->from->lessThan($this->to);
    }
}
