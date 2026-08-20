<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Events;

use App\Modules\Gamification\Models\Redemption;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student claimed a reward, and the teacher owes them something (FR-032).
 *
 * Raised after commit: a listener that ran inside the transaction would announce
 * a claim that may still roll back — and on a real queue could start before the
 * commit and find no row at all.
 */
class RewardRedeemed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Redemption $redemption) {}
}
