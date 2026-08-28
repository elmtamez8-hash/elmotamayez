<?php

declare(strict_types=1);

namespace App\Modules\Courses\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

enum CourseVisibility: string implements HasArabicLabel
{
    use BuildsOptions;

    case Public = 'public';
    case Private = 'private';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'عامّ',
            self::Private => 'خاصّ',
            self::Hidden => 'مخفيّ',
        };
    }
}
