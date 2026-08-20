<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\LevelResource\Pages;

use App\Modules\Gamification\Filament\Resources\LevelResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLevel extends CreateRecord
{
    protected static string $resource = LevelResource::class;
}
