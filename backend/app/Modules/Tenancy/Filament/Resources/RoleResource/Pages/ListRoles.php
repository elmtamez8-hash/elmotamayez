<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources\RoleResource\Pages;

use App\Modules\Tenancy\Filament\Resources\RoleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('دور جديد'),
        ];
    }
}
