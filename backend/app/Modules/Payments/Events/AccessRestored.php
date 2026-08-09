<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\CreditBalance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The withholding predicate flipped back to false for one course.
 *
 * Announcement only — nothing has to ACT on it to restore access. The predicate
 * is evaluated at every booking and at every media grant, so the student is
 * already unblocked on their next attempt; this event exists so they can be told
 * rather than left to discover it. Its four dispatch sites are the same as
 * {@see AccessWithheld}'s.
 */
class AccessRestored
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CreditBalance $balance,
    ) {}
}
