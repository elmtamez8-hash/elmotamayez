<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\SchoolYearResource\Pages;

use App\Modules\Marketplace\Filament\Resources\SchoolYearResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSchoolYear extends CreateRecord
{
    protected static string $resource = SchoolYearResource::class;
}
