<?php

declare(strict_types=1);

namespace App\Filament\Resources\EnrollmentResource\Pages;

use App\Filament\Resources\EnrollmentResource;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Support\SubscriptionAccess;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
     * ⚠️ وتسجيلُ اشتراكٍ انتهى وصولُه لا يُعادُ فتحُه من هنا
     * ({@see EnrollmentResource::isLapsedSubscription()}): وصولٌ مدفوعٌ لمدّةٍ
     * يُفتَحُ بلا دفعٍ ولا اشتراكٍ يُغلقُه يوماً.
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

        if ($record instanceof Enrollment
            && EnrollmentResource::isLapsedSubscription($record)
            && in_array($data['status'] ?? null, Enrollment::GRANTING_STATUSES, true)) {
            throw ValidationException::withMessages([
                'data.status' => 'وصولُ الاشتراكِ يعودُ بتجديده، لا بتعديلِ الحالةِ من هنا.',
            ]);
        }

        return $data;
    }

    /**
     * ⚠️ «منتهٍ» على تسجيلِ اشتراكٍ يمرُّ بـ`SubscriptionAccess::closeEnrollment()`
     * لا بـ`$record->update()`: الكتابةُ الخامُ لا تُطلِقُ `SubscriptionEnded`،
     * فيبقى الطالبُ محجوزاً في حصصِ كورسٍ لم يعدْ يفتحُه — ويُخصَمُ منه عندَ
     * تسليمِ كلِّ حصّة. بقيّةُ الانتقالاتِ تُحفَظُ كما كانت.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Enrollment $record */
        $closesSubscription = $record->source === 'subscription'
            && ($data['status'] ?? null) === EnrollmentStatus::Expired->value
            && in_array($record->status, Enrollment::GRANTING_STATUSES, true);

        if (! $closesSubscription) {
            $record->update($data);

            return $record;
        }

        unset($data['status']);

        return DB::transaction(function () use ($record, $data): Enrollment {
            if ($data !== []) {
                $record->update($data);
            }

            SubscriptionAccess::closeEnrollment($record);

            return $record->refresh();
        });
    }
}
