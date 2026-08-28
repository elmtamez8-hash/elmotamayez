<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Enums;

use App\Shared\Enums\BuildsOptions;
use App\Shared\Enums\HasArabicLabel;

/**
 * مسارُ طلبِ انضمامِ مدرّس.
 *
 * ⚠️ القيمُ نفسُها في ثوابتِ `TeacherApplication::STATUS_*`، وهي المرجعُ
 * الذي تكتبُه الإجراءات؛ هذا النوعُ هو نصفُ العرضِ منها. لا تُحذَفُ الثوابتُ
 * في هذا التغيير: يقرؤها الإجراءُ والسياسةُ والاختبار، وتبديلُها عملٌ
 * منفصلٌ بمجموعةِ اختباراتِه.
 */
enum TeacherApplicationStatus: string implements HasArabicLabel
{
    use BuildsOptions;

    case Draft = 'draft';

    case Submitted = 'submitted';

    case ChangesRequested = 'changes_requested';

    case Approved = 'approved';

    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسوّدة',
            self::Submitted => 'قيد المراجعة',
            self::ChangesRequested => 'بانتظار تعديل',
            self::Approved => 'مقبول',
            self::Rejected => 'مرفوض',
        };
    }
}
