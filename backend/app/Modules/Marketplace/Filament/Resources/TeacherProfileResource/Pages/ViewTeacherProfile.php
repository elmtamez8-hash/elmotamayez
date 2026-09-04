<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\Pages;

use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTeacherProfile extends ViewRecord
{
    protected static string $resource = TeacherProfileResource::class;

    /**
     * القراراتُ هنا، وكلٌّ منها Action — لا حفظَ مباشرٌ ولا حذف.
     *
     * ⚠️ الشاشةُ كانت بلا أزرارٍ إطلاقاً، والاعتمادُ لا يُتَّخَذُ إلّا من طابورِ
     * الطلبات — فملفٌّ «قيد المراجعة» لا طلبَ له لم يكن يُعتمَدُ من أيِّ مكانٍ في
     * المنتَج، وموقوفٌ لم يكن يُوقَفُ أصلاً. التفرّعُ في
     * {@see TeacherProfileResource::approveAction()}.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            TeacherProfileResource::approveAction(),
            TeacherProfileResource::suspendAction(),
            EditAction::make()->label('تعديل'),
        ];
    }
}
