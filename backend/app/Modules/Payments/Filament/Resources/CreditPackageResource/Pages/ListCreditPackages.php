<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\CreditPackageResource\Pages;

use App\Modules\Payments\Filament\Resources\CreditPackageResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCreditPackages extends ListRecords
{
    protected static string $resource = CreditPackageResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('حزمة جديدة'),
        ];
    }
}
