<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Modules\Gamification\Enums\LeaderboardPeriod;
use App\Modules\Gamification\Models\LeaderboardEntry;
use App\Modules\Gamification\Models\StudentProgress;
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
     * ⚠️ A RANK AND A LEVEL COME FROM DIFFERENT PLACES, AND ONLY ONE OF THEM IS
     * WEEKLY. The boards are rolled up nightly, so a student who signed up this
     * morning has no `leaderboard_entries` row at all — while their level is
     * cumulative, platform-wide and true today. Reading the level off the entry
     * alone would blank it for exactly the newcomer the badge is encouraging, and
     * filling it in from `student_progress` at the CALLER would be Community
     * reading Gamification's tables directly, which Constitution III forbids and
     * no test guards.
     *
     * So: rank and points for whoever the roll-up has seen, level for whoever has
     * earned anything ever, and an ABSENT KEY for a person who has neither — a
     * teacher, an assistant, or a student on their first day. Absence is a state;
     * a zero here is «المركز ٠» beside the teacher's own name in front of the
     * class.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{rank: int|null, points: int|null, level: int|null}> keyed by user id
     */
    public function handle(array $userIds, string $scopeKey, LeaderboardPeriod $period = LeaderboardPeriod::Week): array
    {
        if ($userIds === []) {
            return [];
        }

        $periodKey = $period === LeaderboardPeriod::Week
            ? $this->calendar->weekKey()
            : $this->calendar->termKey();

        // One bulk read, never one per user: the caller renders a page of messages
        // or a roster and a lookup per row is an N+1 by construction.
        $levels = StudentProgress::query()
            ->whereIn('user_id', $userIds)
            ->pluck('level', 'user_id');

        $ranked = LeaderboardEntry::query()
            ->where('scope_key', $scopeKey)
            ->where('period_key', $periodKey)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'rank', 'points', 'level_band'])
            ->mapWithKeys(fn (LeaderboardEntry $entry): array => [
                (int) $entry->user_id => [
                    'rank' => (int) $entry->rank,
                    'points' => (int) $entry->points,
                    /*
                     | ⚠️ THE LEVEL IS THE STUDENT'S, NEVER THE BAND — and reading
                     | the band here printed «المستوى ٠» beside a real student's
                     | name in a live chat. `level_band` is «which band did they
                     | compete in this period», a leaderboard concept derived from
                     | `MAX(award_entries.level_band)`, and an entry whose awards
                     | carry no band is a legitimate 0. Levels in the catalogue run
                     | 1–6, so 0 is not one, and the field meant two different
                     | things depending on whether the nightly roll-up had seen the
                     | person — which is exactly the inconsistency a reader of the
                     | screen cannot see.
                     */
                    'level' => self::realLevel($levels[(int) $entry->user_id] ?? null)
                        ?? self::realLevel($entry->level_band),
                ],
            ])
            ->all();

        foreach ($levels as $userId => $level) {
            $userId = (int) $userId;

            if (isset($ranked[$userId])) {
                continue;
            }

            $ranked[$userId] = ['rank' => null, 'points' => null, 'level' => self::realLevel($level)];
        }

        return $ranked;
    }

    /**
     * A level the catalogue actually has, or null.
     *
     * ⚠️ NULL RATHER THAN ZERO, THE SAME DECISION THE RANK MAKES. Absence is a
     * state and every caller already draws nothing for it; «المستوى ٠» is a
     * number printed beside a student's name in front of their class.
     */
    private static function realLevel(mixed $level): ?int
    {
        return $level === null || (int) $level < 1 ? null : (int) $level;
    }
}
