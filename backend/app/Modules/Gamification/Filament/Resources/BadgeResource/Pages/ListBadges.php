<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\BadgeResource\Pages;

use App\Modules\Gamification\Filament\Resources\BadgeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBadges extends ListRecords
{
    protected static string $resource = BadgeResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('شارة جديدة'),
        ];
    }
}
