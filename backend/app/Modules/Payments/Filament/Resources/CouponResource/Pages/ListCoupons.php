<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\CouponResource\Pages;

use App\Modules\Payments\Filament\Resources\CouponResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCoupons extends ListRecords
{
    protected static string $resource = CouponResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('كوبون جديد'),
        ];
    }
}
