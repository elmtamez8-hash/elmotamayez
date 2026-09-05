<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\SubscriptionResource\Pages;

use App\Modules\Payments\Filament\Resources\SubscriptionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    /**
     * No CreateAction, deliberately.
     *
     * A subscription is bought by a student against a priced plan, and buying it
     * writes an order, a payment and a period all at once. A «اشتراك جديد» button
     * here would be a fourth writer of that row with none of it behind it.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
