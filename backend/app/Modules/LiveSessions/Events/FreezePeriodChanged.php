<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A freeze was declared, edited or lifted.
 *
 * ⚠️ ANNOUNCED FROM THE MODEL, NOT FROM THE ACTION, AND THAT IS THE WHOLE POINT.
 * There are three write paths today — `CreateFreezePeriod`, the controller's
 * `destroy`, and any edit made from `/admin` — and 011 needs all three, because a
 * subscription's `effective_ends_on` is derived from these rows and an extension
 * that outlives its reason is as wrong as one that never happened. Firing from
 * each caller means the fourth path added next year silently is not covered;
 * firing from `booted()` means it is covered the day it lands.
 *
 * ⚠️ IT CARRIES IDENTIFIERS, NOT THE MODEL. A deleted period has no row for a
 * listener to reload, and `SerializesModels` on a queued listener would try —
 * so what travels is the workspace and, when the period named one, the student.
 * Null means the freeze covered every student of that teacher.
 */
class FreezePeriodChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly ?int $studentUserId = null,
    ) {}
}
