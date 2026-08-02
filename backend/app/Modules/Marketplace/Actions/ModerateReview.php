<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Events\ReviewModerated;
use App\Modules\Marketplace\Models\Review;
use App\Shared\Actions\Action;

class ModerateReview extends Action
{
    /**
     * Hidden, not deleted: the unique (teacher, student) pair has to keep holding,
     * otherwise hiding an abusive review hands its author a fresh slot.
     */
    public function handle(Review $review): Review
    {
        $review->forceFill(['is_visible' => false])->save();

        event(new ReviewModerated($review));

        return $review;
    }
}
