<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\Pages;

use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewTeacherProfile extends ViewRecord
{
    protected static string $resource = TeacherProfileResource::class;

    /**
     * لا تعديلَ ولا حذف.
     *
     * Filament يضعُ زرَّ التعديلِ هنا افتراضيّاً، وهو الزرُّ الذي لا يجوزُ أن
     * تحملَه هذه الصفحة: الاعتمادُ يمرُّ بـ Action تُرسِلُ إشعاراً وتُبطِلُ ذاكرةَ
     * السوقِ وتشتقُّ `is_publicly_listed`، وحقلٌ يُحفَظُ من هنا يتخطّاها كلَّها.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
