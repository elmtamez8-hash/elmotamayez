<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\BadgeResource\Pages;

use App\Modules\Gamification\Filament\Resources\BadgeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBadge extends CreateRecord
{
    protected static string $resource = BadgeResource::class;
}
