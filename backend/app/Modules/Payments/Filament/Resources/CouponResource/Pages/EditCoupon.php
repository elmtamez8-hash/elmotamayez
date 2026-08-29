<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\CouponResource\Pages;

use App\Modules\Payments\Filament\Resources\CouponResource;
use Filament\Resources\Pages\EditRecord;

/**
 * ⚠️ NO `DeleteAction` HERE, DELIBERATELY. Retirement is `is_active = false`;
 * the row is pointed at by every `coupon_redemptions` entry made from it, which
 * is FR-015's record of a discount somebody actually received.
 */
class EditCoupon extends EditRecord
{
    protected static string $resource = CouponResource::class;
}
