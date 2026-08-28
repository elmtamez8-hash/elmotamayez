<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * حالةُ ورقةِ الاختبار — نفسُ سُلَّمِ `CourseStatus` وليست هي:
 * النشرُ هنا يفتحُ ورقةً للجلوس، والأرشفةُ تُخفيها ولا تمسُّ محاولةً سابقة.
 */
enum ExamStatus: string implements HasArabicLabel
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
