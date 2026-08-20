<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\GradeLevelResource\Pages;

use App\Modules\Marketplace\Filament\Resources\GradeLevelResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGradeLevels extends ListRecords
{
    protected static string $resource = GradeLevelResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('مرحلة جديدة'),
        ];
    }
}
