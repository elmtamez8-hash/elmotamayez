<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Filament\Resources\GamificationActionResource\Pages;

use App\Modules\Gamification\Filament\Resources\GamificationActionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditGamificationAction extends EditRecord
{
    protected static string $resource = GamificationActionResource::class;

    /**
     * No DeleteAction, deliberately.
     *
     * Filament puts one here by default, and it is the one button this screen must
     * not have: every award entry ever written names its action by KEY, so
     * deleting the row leaves a student's history pointing at nothing. Disabling
     * is the toggle in the form, and it is what every read consults.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
