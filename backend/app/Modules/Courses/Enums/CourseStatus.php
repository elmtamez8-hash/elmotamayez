<?php

declare(strict_types=1);

namespace App\Modules\Courses\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

enum CourseStatus: string implements HasArabicLabel
{
    use BuildsOptions;

    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسوّدة',
            self::Published => 'منشور',
            self::Archived => 'مؤرشف',
        };
    }
}
