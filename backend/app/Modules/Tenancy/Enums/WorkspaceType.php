<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * نوعُ مساحةِ العمل — عمودُ `enum` حقيقيٌّ في الهجرة، فالمجموعةُ هنا
 * تطابقُ ما تقبلُه قاعدةُ البيانات حرفاً بحرف.
 */
enum WorkspaceType: string implements HasArabicLabel
{
    use BuildsOptions;

    case Teacher = 'teacher';

    case Academy = 'academy';

    case School = 'school';

    public function label(): string
    {
        return match ($this) {
            self::Teacher => 'مدرّس',
            self::Academy => 'أكاديميّة',
            self::School => 'مدرسة',
        };
    }
}
