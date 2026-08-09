<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * What an order buys.
 *
 * Without this column CreateEnrollmentFromOrder cannot tell the two apart: it
 * branches on `course_id === null` alone, and a credit order carries a course by
 * its nature (Q-7 makes the course the pricing context). So a student buying
 * credits would be enrolled in the whole course for free.
 *
 * It is also what OrderPolicy::approve reads to keep credit purchases out of the
 * teacher's hands — see Permissions::BILLING_PURCHASE_APPROVE.
 */
enum OrderKind: string
{
    /** The shipped one-off course purchase. Priced by `courses.price`. */
    case Course = 'course';

    /** A credit package, priced by CostPlusPricing for that course. */
    case Credits = 'credits';

    public function label(): string
    {
        return match ($this) {
            self::Course => 'شراء كورس',
            self::Credits => 'شراء أرصدة',
        };
    }
}
