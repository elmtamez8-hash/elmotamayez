<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\RegionResource\Pages;

use App\Modules\Marketplace\Filament\Resources\RegionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegions extends ListRecords
{
    protected static string $resource = RegionResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('منطقة جديدة'),
        ];
    }
}
