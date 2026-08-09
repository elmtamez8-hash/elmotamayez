<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Data\PackagePrice;
use App\Modules\Payments\Models\CreditPackage;
use App\Shared\Contracts\ApprovedRateDirectory;
use DateTimeInterface;
use DomainException;

/**
 * The cost-plus formula, in one place (FR-021).
 *
 *     teacher's approved rate + fixed operating fee, then the gateway on top
 *
 * The operating fee is a FIXED amount per session type, never a percentage of
 * the teacher's rate (FR-021أ): hosting a session costs the platform the same
 * whether the teacher asks 50 or 500.
 *
 * ⚠️ THE GATEWAY IS GROSSED UP, NOT ADDED ON. A gateway that keeps 2.5% keeps it
 * out of what it COLLECTS, so charging `subtotal × 1.025` and handing it over
 * comes back 2.5% of the fee short — every time, on every purchase, with the
 * difference landing silently on the platform's margin. The amount to charge is
 * the one that nets the subtotal after the cut:
 *
 *     total × (1 − bps/10000) − fixed = subtotal
 *     total = (subtotal + fixed) × 10000 ÷ (10000 − bps)
 *
 * Rounded UP, because rounding down is the same shortfall a rial at a time.
 *
 * ⚠️ NO APPROVED RATE MEANS NO PRICE, AND NO PRICE MEANS NO PACKAGES. Null is
 * returned rather than a default: a default price is a number nobody approved,
 * charged to a student and owed to a teacher who never agreed to it.
 */
class CostPlusPricing
{
    public function __construct(
        private readonly ApprovedRateDirectory $rates,
        private readonly BillingSettings $settings,
    ) {}

    /**
     * What this package costs on this course, or null when it cannot be priced.
     */
    public function price(
        CreditPackage $package,
        int $courseId,
        DateTimeInterface $moment,
    ): ?PackagePrice {
        $rate = $this->rates->approvedRateMinorForCourse($courseId, $package->session_type, $moment);

        if ($rate === null) {
            return null;
        }

        return $this->compose($package->credits, $rate, $package->session_type);
    }

    /**
     * The formula itself, with the rate already resolved.
     *
     * Separate from {@see self::price()} so the arithmetic is testable without a
     * course, a teacher profile and an approved rate behind it — and so the one
     * caller that already holds a rate does not resolve it twice.
     */
    public function compose(int $credits, int $rateMinor, ClassSessionType $type): PackagePrice
    {
        $teacher = $rateMinor * $credits;
        $operating = $this->settings->operatingFeeMinor($type) * $credits;
        $subtotal = $teacher + $operating;

        $bps = $this->settings->gatewayFeeBps();
        $fixed = $this->settings->gatewayFixedFeeMinor();

        if ($bps >= 10_000) {
            // A gateway keeping the entire payment prices nothing; the equation
            // has no solution. Refusing loudly beats charging a number produced
            // by a division that went negative.
            throw new DomainException('نسبة رسوم البوابة غير صالحة، فلا يمكن تسعير الحزم.');
        }

        $total = $this->ceilDiv(($subtotal + $fixed) * 10_000, 10_000 - $bps);

        return new PackagePrice(
            credits: $credits,
            teacherRateMinor: $teacher,
            operatingFeeMinor: $operating,
            // Derived by subtraction so the four always sum to the total, even
            // where the rounding up added a minor unit. Computing it
            // independently is how the identity PackagePrice promises breaks.
            gatewayFeeMinor: $total - $subtotal,
            totalMinor: $total,
            currency: $this->settings->currency(),
        );
    }

    /** Integer ceiling division. `ceil()` on money is a float on money. */
    private function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
