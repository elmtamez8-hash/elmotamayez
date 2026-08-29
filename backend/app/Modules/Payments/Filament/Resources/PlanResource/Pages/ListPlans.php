<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\PlanResource\Pages;

use App\Modules\Payments\Filament\Resources\PlanResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    /**
     * No CreateAction, deliberately.
     *
     * A plan is the TEACHER's: they decide how long a month of them lasts and
     * what it covers (FR-025). This screen is the platform's half — the price —
     * and a «باقة جديدة» button here would let an officer invent a product in
     * somebody else's workspace.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
