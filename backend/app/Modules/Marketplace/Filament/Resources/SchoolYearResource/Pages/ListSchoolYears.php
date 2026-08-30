<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\SchoolYearResource\Pages;

use App\Modules\Marketplace\Filament\Resources\SchoolYearResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSchoolYears extends ListRecords
{
    protected static string $resource = SchoolYearResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('صف جديد'),
        ];
    }
}
