<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * What a coupon may be spent on (spec 011 · FR-012).
 *
 * A null `scope_type` on the row means «anything», which is the ordinary
 * seasonal-campaign shape. A value narrows it to ONE subject, identified by uuid
 * — the spec's own edge case: «a coupon on a product and on a course fee at once
 * — its scope is stated explicitly and it does not apply outside it».
 *
 * ⚠️ THE THREE CASES ARE THE THREE PURCHASE PATHS, and that is not a
 * coincidence to be tidied up later. A coupon whose scope names a kind no path
 * can present is a coupon that can never be spent, and it would be refused with
 * the same sentence as a code that does not exist — indistinguishable, by
 * design, from a typo.
 */
enum CouponScope: string
{
    case Course = 'course';
    case StoreItem = 'store_item';
    case CreditPackage = 'credit_package';

    public function label(): string
    {
        return match ($this) {
            self::Course => 'كورس بعينه',
            self::StoreItem => 'منتج بعينه',
            self::CreditPackage => 'حزمة أرصدة بعينها',
        };
    }
}
