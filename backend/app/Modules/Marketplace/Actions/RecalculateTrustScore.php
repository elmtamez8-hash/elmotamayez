<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Models\Complaint;
use App\Modules\Marketplace\Models\Review;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Marketplace\Support\TrustScoreCalculator;
use App\Shared\Actions\Action;

class RecalculateTrustScore extends Action
{
    public function __construct(private readonly TrustScoreCalculator $calculator) {}

    public function handle(TeacherProfile $teacher): TeacherProfile
    {
        // Counters first, then the score: the calculator reads reviews_count and
        // average_rating off the profile, so recomputing them here is what keeps a
        // hidden review from still counting towards the number shown publicly.
        $this->refreshReviewCounters($teacher);

        $confirmed = Complaint::query()
            ->withoutWorkspaceScope()
            ->where('teacher_profile_id', $teacher->getKey())
            ->where('status', Complaint::STATUS_CONFIRMED)
            ->count();

        $result = $this->calculator->calculate($teacher, $confirmed);

        $teacher->forceFill([
            'trust_score' => $result['score'],
            'trust_score_factors' => $result['factors'],
            'trust_score_calculated_at' => now(),
        ])->save();

        MarketplaceCache::flush();

        return $teacher;
    }

    /**
     * reviews_count and average_rating are materialised on the profile so the
     * listing can sort on them; they are derived, so they are recomputed from the
     * rows rather than incremented — an increment that runs twice is permanent.
     */
    private function refreshReviewCounters(TeacherProfile $teacher): void
    {
        $visible = Review::query()
            ->withoutWorkspaceScope()
            ->where('teacher_profile_id', $teacher->getKey())
            ->where('is_visible', true);

        $count = (clone $visible)->count();
        $average = $count === 0 ? null : round((float) (clone $visible)->avg('rating'), 2);

        $teacher->forceFill([
            'reviews_count' => $count,
            'average_rating' => $average,
        ]);
    }
}
