<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\Pages;

use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListTeacherProfiles extends ListRecords
{
    protected static string $resource = TeacherProfileResource::class;

    /**
     * لا زرَّ إنشاء.
     *
     * الملفُّ يولدُ من قبولِ طلبِ الانضمام؛ صفٌّ يُنشأُ من هنا لا يقابلُه طلبٌ
     * ولا مستخدِمٌ ولا مساحةُ عمل.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
