<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\CouponResource\Pages;

use App\Modules\Payments\Filament\Resources\CouponResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCoupon extends CreateRecord
{
    protected static string $resource = CouponResource::class;

    protected function beforeCreate(): void
    {
        if (CouponResource::refusedForTwoFactor()) {
            $this->halt();
        }
    }

    /**
     * Who wrote it, recorded without asking.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }
}
