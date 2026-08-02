<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Events;

use App\Modules\Marketplace\Models\Review;
use Illuminate\Foundation\Events\Dispatchable;

class ReviewModerated
{
    use Dispatchable;

    public function __construct(public readonly Review $review) {}

    public function teacherProfileId(): int
    {
        return (int) $this->review->teacher_profile_id;
    }
}
