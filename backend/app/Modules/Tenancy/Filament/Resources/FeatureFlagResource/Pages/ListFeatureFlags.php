<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources\FeatureFlagResource\Pages;

use App\Modules\Tenancy\Filament\Resources\FeatureFlagResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeatureFlags extends ListRecords
{
    protected static string $resource = FeatureFlagResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('مفتاح جديد'),
        ];
    }
}
