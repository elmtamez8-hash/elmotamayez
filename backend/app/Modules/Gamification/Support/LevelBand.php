<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Support;

/**
 * Which competitive band a level falls in (FR-023).
 *
 * ⚠️ THE SLICE IS BY LEVEL FIRST AND BY RANK SECOND, never by rank alone.
 * `floor(rank / 50)` satisfies SC-009 to the letter — no slice exceeds fifty —
 * and puts a level-2 student having a good week among level-40 grinders, which is
 * the exact opposite of why the slice exists. The source document is explicit:
 * "competition motivates only while winning looks possible".
 */
class LevelBand
{
    public function __construct(private readonly GamificationSettings $settings) {}

    public function for(int $level): int
    {
        return intdiv(max(0, $level - 1), $this->settings->levelBandWidth());
    }
}
