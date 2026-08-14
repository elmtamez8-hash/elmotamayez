<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources\PlatformStaffResource\Pages;

use App\Modules\Tenancy\Filament\Resources\PlatformStaffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlatformStaff extends ListRecords
{
    protected static string $resource = PlatformStaffResource::class;

    /** @return array<int, CreateAction> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('تفويض شخص')];
    }
}
