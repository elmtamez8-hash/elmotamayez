<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\BadgeResource\Pages;

use App\Modules\Gamification\Filament\Resources\BadgeResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditBadge extends EditRecord
{
    protected static string $resource = BadgeResource::class;

    /**
     * No DeleteAction, deliberately.
     *
     * Every award and every badge a student holds names its catalogue row by KEY.
     * Deleting the row leaves that history pointing at nothing and the profile
     * showing a raw slug where a name used to be. Disabling is the toggle.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
