<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\WorkspaceResource;
use App\Filament\Support\RecordLinkAction;
use App\Models\User;
use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Models\Workspace;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

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
     * ⚠️ والرابطانِ الأخيرانِ لمديرِ المنصّةِ عملياً: مسؤولُ الامتثالِ يقرأُ هذه
     * الصفحةَ ولا يفتحُ شاشةَ الحساباتِ ولا أماكنِ العمل، فلا يُرسَمانِ له.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            TeacherProfileResource::approveAction(),
            TeacherProfileResource::suspendAction(),
            EditAction::make()->label('تعديل'),
            RecordLinkAction::make('openUser', 'حساب المدرّس', UserResource::class, fn (): ?User => $this->profile()?->user, Heroicon::OutlinedUser),
            RecordLinkAction::make('openWorkspace', 'مكان العمل', WorkspaceResource::class, fn (): ?Workspace => $this->profile()?->workspace, Heroicon::OutlinedBuildingLibrary),
        ];
    }

    private function profile(): ?TeacherProfile
    {
        $record = $this->getRecord();

        return $record instanceof TeacherProfile ? $record : null;
    }
}
