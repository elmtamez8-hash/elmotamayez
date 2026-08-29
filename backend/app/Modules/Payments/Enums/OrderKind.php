<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Modules\Payments\Policies\OrderPolicy;

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

    /** A book or a set of notes from the teacher's store (spec 011 · US1). */
    case Store = 'store';

    /** A subscription plan, priced by the platform (spec 011 · FR-025). */
    case Subscription = 'subscription';

    public function label(): string
    {
        return match ($this) {
            self::Course => 'شراء كورس',
            self::Credits => 'شراء أرصدة',
            self::Store => 'شراء من المتجر',
            self::Subscription => 'اشتراك',
        };
    }

    /**
     * Whose signature says the money arrived.
     *
     * ⚠️ THE SELLER NEVER WITNESSES THEIR OWN RECEIPT, and `PAYMENTS_APPROVE`
     * plus the workspace check is a bar the seller clears by definition — which
     * is the whole of what {@see OrderPolicy::approve()}
     * already says in prose about a credit purchase. A store sale is the
     * teacher's own goods and a subscription is the platform's own plan, so in
     * both the person who would press the button is the person being paid.
     *
     * A course order is deliberately NOT here: it shipped under
     * `PAYMENTS_APPROVE` and spec 011 changes nothing about it.
     */
    public function requiresPlatformApproval(): bool
    {
        return $this !== self::Course;
    }

    /**
     * The refusal, per kind, because a sentence that names the wrong thing is
     * worse than a vague one: a teacher told "شراء الأرصدة" about a book they
     * sold goes looking for a credit purchase that does not exist.
     *
     * @param  'approve'|'reject'|'view'  $act
     */
    public function platformRefusal(string $act): string
    {
        $what = match ($this) {
            self::Credits => 'شراء الأرصدة',
            self::Store => 'طلبات المتجر',
            self::Subscription => 'الاشتراكات',
            self::Course => 'هذا الطلب',
        };

        return match ($act) {
            'approve' => "اعتماد {$what} صلاحية منصّية.",
            'reject' => "رفض {$what} صلاحية منصّية.",
            default => "{$what} بين الطالب والمنصّة.",
        };
    }

    /**
     * What belongs in the teacher's order list and the panel's order table.
     *
     * ⚠️ AN ALLOWLIST, AND THE DENYLIST IT REPLACES IS THE POINT. Both readers
     * were written `where('kind', '!=', Credits)` — correct while the enum had two
     * cases and a silent widening the moment it had four: adding `Store` and
     * `Subscription` would have dropped every store sale and every subscription
     * into a teacher's order list and into `/admin/orders`, with nothing to say
     * so. A list declared by what it CONTAINS cannot grow that way; the next case
     * added is absent until somebody puts it here on purpose.
     *
     * Byte-identical to the old predicate today. That is the whole intention.
     *
     * @return list<string>
     */
    public static function teacherListedValues(): array
    {
        return [self::Course->value];
    }
}
