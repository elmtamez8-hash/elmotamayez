<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Modules\Gamification\Models\Badge;
use App\Modules\Gamification\Models\BadgeAward;
use App\Shared\Actions\Action;
use Illuminate\Support\Collection;

/**
 * The badges of several students at once — the sibling of {@see ReadRanksFor}.
 *
 * ⚠️ BULK FOR THE SAME REASON, AND IT IS NOT A STYLE PREFERENCE. The caller
 * renders one row per participant, so a lookup inside a Resource is an N+1 by
 * construction — the defect `ClassSessionResource` cost this repository once and
 * `ChatRankStamper` was written bulk to avoid reaching it a second time.
 *
 * ⚠️ AND AN EMPTY LIST IS A STATE, NOT A MISSING ROW. A teacher, an assistant and
 * a student on their first day all hold nothing, and the screen must draw nothing
 * where the row of badges goes — never a placeholder, and never a dash.
 *
 * The catalogue is read once and joined in memory rather than with a SQL join:
 * `badges` is small platform reference data, and the join key is a STRING key
 * (`badge_key`) precisely so a re-seeded catalogue keeps every award already
 * granted. A student holding a badge whose row was deleted is skipped rather
 * than rendered as a blank chip.
 */
class ReadBadgesFor extends Action
{
    /**
     * @param  list<int>  $userIds
     * @param  int  $limitPerUser  the newest N, because a row of chips beside a
     *                             name has a width and a participant list with
     *                             thirty badges on one line is unreadable
     * @return array<int, list<array{key: string, name_ar: string, icon: string|null}>> keyed by user id
     */
    public function handle(array $userIds, int $limitPerUser = 6): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var Collection<int, BadgeAward> $awards */
        $awards = BadgeAward::query()
            ->whereIn('user_id', $userIds)
            ->orderByDesc('awarded_at')
            ->get(['user_id', 'badge_key', 'awarded_at']);

        if ($awards->isEmpty()) {
            return [];
        }

        $catalogue = Badge::query()
            ->whereIn('key', $awards->pluck('badge_key')->unique()->all())
            ->get(['key', 'name_ar', 'icon'])
            ->keyBy('key');

        $byUser = [];

        foreach ($awards as $award) {
            $badge = $catalogue->get($award->badge_key);

            if ($badge === null) {
                continue;
            }

            $userId = (int) $award->user_id;

            if (count($byUser[$userId] ?? []) >= $limitPerUser) {
                continue;
            }

            $byUser[$userId][] = [
                'key' => (string) $badge->key,
                'name_ar' => (string) $badge->name_ar,
                'icon' => $badge->icon === null ? null : (string) $badge->icon,
            ];
        }

        return $byUser;
    }
}
