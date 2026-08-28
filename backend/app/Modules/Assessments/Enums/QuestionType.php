<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * أنواعُ السؤالِ الثلاثة.
 *
 * ⚠️ ولا واحدٌ منها يعني «اختر كلَّ ما ينطبق»: كلُّ سطحِ إجابةٍ في المنتَج
 * أُحاديُّ الاختيار، وسؤالٌ بخيارَينِ صحيحَينِ لا تُرضيه نقرةٌ واحدةٌ أبداً.
 * نوعٌ متعدّدُ الصوابِ يحتاجُ نوعَه وشاشتَه وقاعدةَ درجاتِه الجزئيّة.
 */
enum QuestionType: string implements HasArabicLabel
{
    use BuildsOptions;

    case Mcq = 'mcq';

    case TrueFalse = 'true_false';

    case Essay = 'essay';

    public function label(): string
    {
        return match ($this) {
            self::Mcq => 'اختيار من متعدّد',
            self::TrueFalse => 'صواب وخطأ',
            self::Essay => 'مقاليّ',
        };
    }
}
