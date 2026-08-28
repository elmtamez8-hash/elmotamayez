<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Filament\Resources\DataRequestResource\Pages;

use App\Modules\Compliance\Filament\Resources\DataRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDataRequests extends ListRecords
{
    protected static string $resource = DataRequestResource::class;
}
