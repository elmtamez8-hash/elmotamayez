<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\Cache;

/**
 * Headline counters for the home page.
 *
 * Computed from real rows, not hardcoded (FR-038): a stat bar that invents its
 * numbers is a trust claim the product cannot back up.
 */
class GetMarketplaceStats extends Action
{
    /** @return array{students: int, teachers: int, sessions: int, satisfaction_rate: int} */
    public function handle(): array
    {
        return Cache::remember(
            MarketplaceCache::key('stats'),
            MarketplaceCache::ttl(),
            function (): array {
                $teachers = TeacherProfile::query()->publiclyListed();

                $aggregate = TeacherProfile::query()
                    ->publiclyListed()
                    ->selectRaw('coalesce(sum(students_taught_count), 0) as students')
                    ->selectRaw('coalesce(sum(completed_sessions_count), 0) as sessions')
                    ->selectRaw('avg(average_rating) as rating')
                    ->first();

                $rating = $aggregate?->getAttribute('rating');

                return [
                    'students' => (int) ($aggregate?->getAttribute('students') ?? 0),
                    'teachers' => $teachers->count(),
                    'sessions' => (int) ($aggregate?->getAttribute('sessions') ?? 0),
                    // Average stars out of 5 expressed as a percentage.
                    'satisfaction_rate' => $rating === null ? 0 : (int) round((float) $rating / 5 * 100),
                ];
            },
        );
    }
}
