<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Modules\Gamification\Enums\LeaderboardPeriod;
use App\Modules\Gamification\Models\LeaderboardEntry;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Gamification\Support\GamificationFieldAllowlist;
use App\Shared\Actions\Action;

/**
 * The rank and band of several students at once (FR-042).
 *
 * The source document is emphatic that the strongest placement for a rank is
 * beside a student's name in the class chat, in front of their classmates —
 * social pride outweighing any number on a profile page. That screen is spec
 * 010's; this is the read it will call.
 *
 * ⚠️ BULK, AND THAT IS THE WHOLE DESIGN. The caller has a list of participants and
 * renders one row each, so a per-student lookup here is an N+1 by construction —
 * the defect `ClassSessionResource` already cost this repository once. It was also
 * specified as existing and had no implementation in any file at all, which is a
 * different way for a requirement to be missing.
 *
 * ⚠️ AND IT RETURNS ONLY ALLOWLISTED FIELDS. A rank beside a name is a public
 * surface; anything else added here travels straight into a chat window.
 *
 * @see GamificationFieldAllowlist
 */
class ReadRanksFor extends Action
{
    public function __construct(private readonly GamificationCalendar $calendar) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, array{rank: int, points: int, level: int}> keyed by user id
     */
    public function handle(array $userIds, string $scopeKey, LeaderboardPeriod $period = LeaderboardPeriod::Week): array
    {
        if ($userIds === []) {
            return [];
        }

        $periodKey = $period === LeaderboardPeriod::Week
            ? $this->calendar->weekKey()
            : $this->calendar->termKey();

        return LeaderboardEntry::query()
            ->where('scope_key', $scopeKey)
            ->where('period_key', $periodKey)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'rank', 'points', 'level_band'])
            ->mapWithKeys(fn (LeaderboardEntry $entry): array => [
                (int) $entry->user_id => [
                    'rank' => $entry->rank,
                    'points' => $entry->points,
                    'level' => $entry->level_band,
                ],
            ])
            ->all();
    }
}
