<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Filament\Resources\GradeLevelResource\Pages;

use App\Modules\Marketplace\Filament\Resources\GradeLevelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGradeLevel extends CreateRecord
{
    protected static string $resource = GradeLevelResource::class;
}
