<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Resources\FeatureFlagResource\Pages;

use App\Modules\Tenancy\Filament\Resources\FeatureFlagResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFeatureFlag extends EditRecord
{
    protected static string $resource = FeatureFlagResource::class;

    /**
     * Deleting a row is a real operation here, unlike on the taxonomy screens:
     * nothing stores a reference to a flag, and an absent key reads as «off».
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
