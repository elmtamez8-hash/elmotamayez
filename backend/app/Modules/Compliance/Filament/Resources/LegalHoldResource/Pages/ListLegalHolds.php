<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Filament\Resources\LegalHoldResource\Pages;

use App\Modules\Compliance\Filament\Resources\LegalHoldResource;
use Filament\Resources\Pages\ListRecords;

class ListLegalHolds extends ListRecords
{
    protected static string $resource = LegalHoldResource::class;
}
