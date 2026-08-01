<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\TeacherProfile;

/**
 * Pure computation of a teacher's trust score. No persistence, no queries — the
 * Action that owns the write calls this and stores the result.
 *
 * @phpstan-type Factors array{student_rating: int, punctuality: int, completion: int, tenure: int, complaints_penalty: int}
 */
final class TrustScoreCalculator
{
    /**
     * Returns null when the profile has too little history to score fairly.
     *
     * @return array{score: int|null, factors: Factors|null}
     */
    public function calculate(TeacherProfile $profile, int $confirmedComplaints = 0): array
    {
        /** @var array<string, mixed> $config */
        $config = config('marketplace.trust_score');

        if (! $this->hasEnoughHistory($profile, $config)) {
            return ['score' => null, 'factors' => null];
        }

        $factors = [
            'student_rating' => $this->ratingFactor($profile),
            'punctuality' => (int) ($profile->attendance_rate ?? 0),
            'completion' => $this->completionFactor($profile),
            'tenure' => $this->tenureFactor($profile, (int) $config['tenure_saturation_months']),
            'complaints_penalty' => $this->complaintPenalty($confirmedComplaints, $config),
        ];

        /** @var array<string, int> $weights */
        $weights = $config['weights'];

        $weighted = 0.0;
        foreach ($weights as $key => $weight) {
            $weighted += ($factors[$key] ?? 0) * ($weight / 100);
        }

        // Clamp before returning: the column is unsignedTinyInteger. SQLite would
        // store an out-of-range value happily and MySQL strict mode would reject it,
        // so the bug would only ever surface in production.
        $score = (int) max(0, min(100, round($weighted - $factors['complaints_penalty'])));

        return ['score' => $score, 'factors' => $factors];
    }

    /** @param array<string, mixed> $config */
    private function hasEnoughHistory(TeacherProfile $profile, array $config): bool
    {
        return $profile->completed_sessions_count >= (int) $config['minimum_sessions']
            && $profile->reviews_count >= (int) $config['minimum_reviews'];
    }

    /** Average rating out of 5, expressed as a percentage. */
    private function ratingFactor(TeacherProfile $profile): int
    {
        return (int) round(((float) ($profile->average_rating ?? 0)) / 5 * 100);
    }

    private function completionFactor(TeacherProfile $profile): int
    {
        $booked = $profile->completed_sessions_count + $profile->cancelled_sessions_count;

        if ($booked === 0) {
            return 100;
        }

        return (int) round($profile->completed_sessions_count / $booked * 100);
    }

    private function tenureFactor(TeacherProfile $profile, int $saturationMonths): int
    {
        if ($profile->first_session_at === null || $saturationMonths <= 0) {
            return 0;
        }

        $months = $profile->first_session_at->diffInMonths(now());

        return (int) round(min(1, $months / $saturationMonths) * 100);
    }

    /** @param array<string, mixed> $config */
    private function complaintPenalty(int $confirmedComplaints, array $config): int
    {
        return (int) min(
            $confirmedComplaints * (int) $config['complaint_penalty_per_item'],
            (int) $config['complaint_penalty_max'],
        );
    }
}
