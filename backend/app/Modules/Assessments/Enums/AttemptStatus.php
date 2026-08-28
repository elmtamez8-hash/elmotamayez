<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * أينَ وصلَتْ محاولةُ طالبٍ على ورقة.
 *
 * ⚠️ `pending_grading` حالةٌ لا رايةٌ فوقَ «مصحَّحة»، ووجودُها هو ما يجعلُ
 * سؤالاً مقاليّاً قابلاً للانتظارِ دونَ أن يُقرأ صفراً — راجعْ هجرةَ
 * `extend_exam_attempts`. و«منتهية» و«متروكة» ليستا هنا لأنّ لا سطرَ في
 * الشجرةِ يكتبُهما: قيمةٌ في مجموعةٍ بلا كاتبٍ هي متطلَّبٌ يظنُّه الجميعُ
 * منفَّذاً.
 */
enum AttemptStatus: string implements HasArabicLabel
{
    use BuildsOptions;

    case InProgress = 'in_progress';

    case Submitted = 'submitted';

    case PendingGrading = 'pending_grading';

    case Grading = 'grading';

    case Graded = 'graded';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'جارية',
            self::Submitted => 'مُسلَّمة',
            self::PendingGrading => 'بانتظار التصحيح',
            self::Grading => 'قيد التصحيح',
            self::Graded => 'مصحَّحة',
        };
    }
}
