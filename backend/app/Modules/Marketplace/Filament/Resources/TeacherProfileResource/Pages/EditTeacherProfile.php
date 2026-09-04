<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\Pages;

use App\Modules\Marketplace\Actions\UpdateTeacherProfile;
use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource;
use App\Modules\Marketplace\Models\TeacherProfile;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * تصحيحُ بياناتِ ملفٍّ — والحفظُ يمرُّ بالـAction لا بـ Filament.
 *
 * ⚠️ `handleRecordUpdate` موجودٌ لكي لا ينادِيَ Filament `$record->update($data)`
 * أبداً. `approval_status` و`is_publicly_listed` كلاهما في `$fillable`، فحفظٌ
 * مباشرٌ يكتبُهما إن تسرّبا إلى المصفوفةِ من أيِّ حقلٍ يُضافُ غداً — ويتخطّى معهما
 * إبطالَ ذاكرةِ السوق، فيبقى التصحيحُ غيرَ مرئيٍّ للطلاب. القائمةُ البيضاءُ في
 * {@see UpdateTeacherProfile} هي الحارس، ولا تُكرَّرُ هنا.
 *
 * ⚠️ ولا زرَّ حذف: {@see TeacherProfileResource::canDelete()} يرفض. ملفٌّ يُحذَفُ
 * يتركُ طلبَه يشيرُ إلى لا شيء.
 */
class EditTeacherProfile extends EditRecord
{
    protected static string $resource = TeacherProfileResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            TeacherProfileResource::approveAction(),
            TeacherProfileResource::suspendAction(),
        ];
    }

    /**
     * الموادُّ والمراحلُ علاقتانِ لا عمودان، فلا يملؤهما Filament من الصفّ.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var TeacherProfile $record */
        $record = $this->getRecord();

        $data['subjects'] = $record->subjects()->pluck('subjects.id')->all();
        $data['grade_levels'] = $record->gradeLevels()->pluck('grade_levels.id')->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TeacherProfile $record */
        return app(UpdateTeacherProfile::class)->handle(
            $record,
            $data,
            // ⚠️ `array_values`: مفاتيحُ حالةِ Filament ليست مصفوفةً مرصوصةً بعدَ
            // إزالةِ خيارٍ من المنتقي، و`sync()` لا تُبالي — لكنّ التوقيعَ قائمة.
            array_values(array_map(intval(...), $data['subjects'] ?? [])),
            array_values(array_map(intval(...), $data['grade_levels'] ?? [])),
        );
    }
}
