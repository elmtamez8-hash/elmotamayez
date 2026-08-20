<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Events;

use App\Models\User;
use App\Modules\Gamification\Models\Badge;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A badge was earned, for the first and only time (FR-017).
 *
 * Raised after commit, for the reason written on {@see LevelReachedUp}.
 */
class BadgeAwarded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $student,
        public readonly Badge $badge,
    ) {}
}
