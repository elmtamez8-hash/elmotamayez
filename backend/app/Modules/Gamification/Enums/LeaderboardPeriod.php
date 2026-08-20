<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Enums;

/**
 * Weekly is the ranking; the term is a hall of fame beside it (FR-021 · FR-022).
 *
 * A cumulative ranking from the start of the year makes catching up impossible
 * for anyone who joined late or had a bad month, so they stop trying — which is
 * the whole reason the primary board resets every week.
 */
enum LeaderboardPeriod: string
{
    case Week = 'week';
    case Term = 'term';
}
