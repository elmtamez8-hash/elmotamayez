<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\SubjectResource\Pages;

use App\Modules\Marketplace\Filament\Resources\SubjectResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubjects extends ListRecords
{
    protected static string $resource = SubjectResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('مادّة جديدة'),
        ];
    }
}
