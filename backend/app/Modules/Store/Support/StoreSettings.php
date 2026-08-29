<?php

declare(strict_types=1);

namespace App\Modules\Store\Support;

use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * The two numbers a store sale depends on, and where they are allowed to live.
 *
 * ⚠️ `platform_settings` FIRST, `config/store.php` AS THE FALLBACK. A commission
 * that can only change by shipping code is a commission nobody ever tunes —
 * the rule this repository already applies to the device limit, the upload
 * ceilings and the grant ttl.
 *
 * ⚠️ AND THE RATE IS BASIS POINTS, SO NO FLOAT EVER TOUCHES THE MONEY. A
 * percentage stored as `0.1` and multiplied by a price is the arithmetic that
 * makes a teacher's payout differ by a riyal from what the statement says;
 * settlement already banned it once and the reason has not changed.
 */
class StoreSettings
{
    public static function commissionBps(): int
    {
        // Clamped: a negative rate would pay the teacher MORE than the buyer
        // paid, and anything above 100% would make `teacher_net_minor` negative
        // — a debt created by a sale.
        $bps = (int) PlatformSettings::get('store.commission_bps', config('store.commission_bps', 1000));

        return max(0, min(10_000, $bps));
    }

    public static function refundWindowHours(): int
    {
        return max(0, (int) PlatformSettings::get('store.refund_window_hours', config('store.refund_window_hours', 48)));
    }

    /**
     * The platform's cut of one line, in minor units.
     *
     * ⚠️ IT IS TAKEN OFF THE GOODS AND NOT OFF THE POSTAGE. The shipping fee is
     * money the teacher hands to a courier; a commission on it is the platform
     * taking a percentage of somebody else's invoice, and it would make a
     * teacher who posts far away earn less on the same book.
     *
     * ⚠️ AND IT FLOORS, WHICH FAVOURS THE TEACHER BY AT MOST ONE UNIT. `intdiv`
     * rather than `round`: rounding a half up on the platform's side is a
     * decision nobody made, and a floor is the same answer on both engines
     * whatever the locale.
     */
    public static function commissionOn(int $goodsMinor): int
    {
        return intdiv(max(0, $goodsMinor) * self::commissionBps(), 10_000);
    }
}
