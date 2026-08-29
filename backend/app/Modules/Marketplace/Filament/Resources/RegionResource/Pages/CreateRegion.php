<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\RegionResource\Pages;

use App\Modules\Marketplace\Filament\Resources\RegionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRegion extends CreateRecord
{
    protected static string $resource = RegionResource::class;
}
