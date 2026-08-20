<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\LevelResource\Pages;

use App\Modules\Gamification\Filament\Resources\LevelResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLevels extends ListRecords
{
    protected static string $resource = LevelResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('مستوى جديد'),
        ];
    }
}
