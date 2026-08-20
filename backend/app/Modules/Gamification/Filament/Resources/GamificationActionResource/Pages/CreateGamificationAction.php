<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\GamificationActionResource\Pages;

use App\Modules\Gamification\Filament\Resources\GamificationActionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGamificationAction extends CreateRecord
{
    protected static string $resource = GamificationActionResource::class;
}
