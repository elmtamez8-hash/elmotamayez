<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * Where a study room is in its short life (spec 012 · US3).
 *
 * ⚠️ THIS IS DERIVED FROM THE CLOCK AND STORED NOWHERE. There is no `status`
 * column on `study_rooms`: «closed» means `now() >= ends_at`, so a stopped worker
 * or a missed job cannot leave a finished room reading as open. `spec.md`'s Key
 * Entities says a room has «a state» — that describes what a person sees, not
 * what a table holds.
 *
 * It is an enum rather than three bare strings for the reason `AdaptiveStatus` is
 * one: the Arabic label belongs beside the value, and a payload that sends both
 * keeps the screen from spelling the mapping a second time.
 */
enum StudyRoomState: string implements HasArabicLabel
{
    use BuildsOptions;

    /** Created, not started yet — `starts_in_minutes` is the invitation window. */
    case Pending = 'pending';
    case Live = 'live';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'لم تبدأ بعد',
            self::Live => 'جارية',
            self::Closed => 'انتهت',
        };
    }

    /** True while the room still accepts a join and an answer. */
    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }
}
