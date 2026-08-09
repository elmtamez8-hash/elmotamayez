<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

/**
 * The only fields a TEACHER may read about a student's credits.
 *
 * FR-021ج keeps the platform's price away from the teacher exactly as FR-021ب
 * keeps the teacher's rate away from the student. Both directions matter,
 * because the total is `(teacher rate + platform constants) × credits`: give a
 * teacher two totals and they solve for the constants; give a student two and
 * they read every teacher's pay.
 *
 * ⚠️ THE SWEEP THAT ENFORCES THIS COVERS `Http/Resources/Manage/` ONLY, and that
 * narrowing is deliberate. `OrderResource` exports `amount`, `currency` and
 * `receipt_url` to the STUDENT WHO PAID, entirely by right — a sweep over the
 * whole module would fail on the day it was written, and the usual response to a
 * guard that fails on arrival is to delete the guard. Settlement's equivalent
 * could be module-wide only because every Resource it owns faces the teacher.
 *
 * ⚠️ AND A FIELD LIST DOES NOT CLOSE THE INFERENCE. A teacher knows their own
 * approved rate, so `purchased_credits` times that rate is a LOWER BOUND on what
 * the student paid. That is inherent to cost-plus and is not a leak this class
 * can remove: the only alternative is hiding the credit count itself, which is
 * the one number the teacher genuinely needs in order to teach. Written down so
 * that the next reader weighs it rather than rediscovering it.
 */
class StudentBalanceAllowlist
{
    /**
     * Nine fields: who, which course, how many credits, and whether they are
     * withheld. No money, no price, no package, no order.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return [
            'student_uuid',
            'student_name',
            'course_uuid',
            'course_title',
            'remaining_credits',
            'purchased_credits',
            'consumed_credits',
            'credit_limit_credits',
            'is_withheld',
        ];
    }

    /**
     * Anything money-shaped. A field named here is a build failure, not a review
     * comment — the sweep asserts absence, and absence is what an allowlist can
     * only claim if something checks for the specific things it excludes.
     *
     * @return list<string>
     */
    public static function forbidden(): array
    {
        return [
            'amount',
            'total_minor',
            'teacher_rate_minor',
            'operating_fee_minor',
            'gateway_fee_minor',
            'currency',
            'price',
            'hourly_rate',
        ];
    }
}
