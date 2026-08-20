<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\GamificationActionResource\Pages;

use App\Modules\Gamification\Filament\Resources\GamificationActionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGamificationActions extends ListRecords
{
    protected static string $resource = GamificationActionResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('فعل جديد'),
        ];
    }
}
