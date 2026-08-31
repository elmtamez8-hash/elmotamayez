<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * Where an adaptive practice session has got to (spec 012 · US1).
 *
 * ⚠️ `Mastered` AND `Ended` ARE TWO OUTCOMES, NOT ONE WITH A FLAG. Only the
 * first awards `concept_mastered` and writes a `concept_masteries` row; a flag
 * makes that condition optional for whoever reads it, and the reader that
 * forgets it awards points for giving up. The same reason `SessionDelivered` is
 * not `SessionCompleted` with a boolean.
 *
 * `Running` is the ONLY state that holds `running_key` — the nullable-unique
 * claim column that makes «one live session per student per concept» a database
 * fact rather than a check somebody remembered to write.
 */
enum AdaptiveStatus: string implements HasArabicLabel
{
    use BuildsOptions;

    case Running = 'running';
    case Mastered = 'mastered';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'جارية',
            self::Mastered => 'أُتقِنت',
            self::Ended => 'انتهت',
        };
    }

    /** True while the session still accepts answers. */
    public function isOpen(): bool
    {
        return $this === self::Running;
    }
}
