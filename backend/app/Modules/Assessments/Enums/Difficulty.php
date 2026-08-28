<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

enum Difficulty: string implements HasArabicLabel
{
    use BuildsOptions;

    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';

    public function label(): string
    {
        return match ($this) {
            self::Easy => 'سهل',
            self::Medium => 'متوسّط',
            self::Hard => 'صعب',
        };
    }
}
