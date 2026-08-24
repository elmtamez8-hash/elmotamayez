<?php

declare(strict_types=1);

namespace App\Modules\Community\Events;

use App\Modules\Community\Models\PeriodicReview;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A periodic assessment became visible to the student and their guardian.
 *
 * Fired by the CLAIMANT of `published_at` alone — the one runner whose conditional
 * UPDATE changed a row. Fired after a read-then-write instead, two clicks send the
 * guardian two WhatsApp messages about one assessment.
 */
class PeriodicReviewPublished
{
    use Dispatchable;

    public function __construct(public readonly PeriodicReview $review) {}
}
