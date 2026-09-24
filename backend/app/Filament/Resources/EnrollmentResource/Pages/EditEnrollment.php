<?php

declare(strict_types=1);

namespace App\Filament\Resources\EnrollmentResource\Pages;

use App\Filament\Resources\EnrollmentResource;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditEnrollment extends EditRecord
{
    protected static string $resource = EnrollmentResource::class;

    /**
     * ⚠️ الحارسُ الثاني، لأنّ القائمةَ المُرشَّحةَ لا تحرسُ إلّا الطلبَ الذي
     * رسمَته. الانتقالُ **إلى** «ملغى» يُرفَضُ هنا: كتابةُ العمودِ وحدَه لا تُطلِقُ
     * `CourseAccessWithdrawn`، فيبقى مقعدٌ محجوزٌ وعضويّةُ مجموعةٍ لطالبٍ لم يعدْ
     * له شيء. الإلغاءُ مكانُه «عكس الدفعة» على الطلب (`ReverseCourseOrder`).
     *
     * وصفٌّ مُلغى أصلاً لا تُقبَلُ له حالةٌ جديدةٌ أيضاً: الحقلُ مُقفَلٌ فلا
     * يُرسَل، وأيُّ قيمةٍ تصلُ رغمَ ذلكَ تُسقَط — إعادةُ الفتحِ شراءٌ جديد.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        $cancelled = EnrollmentStatus::Cancelled->value;

        if ($record instanceof Enrollment && $record->status === $cancelled) {
            unset($data['status']);

            return $data;
        }

        if (($data['status'] ?? null) === $cancelled) {
            throw ValidationException::withMessages([
                'data.status' => 'الإلغاءُ يتمُّ من «عكس الدفعة» في الطلب، لا من هنا.',
            ]);
        }

        return $data;
    }
}
