<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Support\CostPlusPricing;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Contracts\ApprovedRateDirectory;

/*
| FR-021 · FR-021أ — the formula, in one place, with the gateway grossed up.
|
| The direction of the gateway fee is the whole point of this file. A fee the
| gateway takes OUT of what it collects is not the same number as a fee added ON
| TOP, and the difference lands on the platform's margin on every single purchase
| — never large enough to notice, never absent.
*/

function pricingWith(int $bps = 0, int $fixed = 0, int $operating = 0): CostPlusPricing
{
    PlatformSettings::set('billing.gateway_fee_bps', $bps);
    PlatformSettings::set('billing.gateway_fixed_fee_minor', $fixed);
    PlatformSettings::set('billing.operating_fee_minor.individual', $operating);
    PlatformSettings::set('billing.operating_fee_minor.group', $operating);

    return app(CostPlusPricing::class);
}

it('adds a fixed operating fee per session, never a percentage', function (): void {
    // 100 a session, 5 operating, 4 credits — the fee scales with the COUNT, not
    // with the rate. A percentage here would make the platform's cut depend on
    // what the teacher charges, which FR-021أ forbids: hosting costs the same.
    $price = pricingWith(operating: 5)->compose(4, 100, ClassSessionType::Individual);

    expect($price->teacherRateMinor)->toBe(400)
        ->and($price->operatingFeeMinor)->toBe(20)
        ->and($price->totalMinor)->toBe(420)
        ->and($price->gatewayFeeMinor)->toBe(0);
});

it('grosses the gateway up rather than adding it on', function (): void {
    // 2.5% of what the gateway COLLECTS. Charging 1000 hands over 975, so the
    // amount that nets 1000 is 1000 ÷ 0.975 = 1025.64… → 1026.
    $price = pricingWith(bps: 250)->compose(10, 100, ClassSessionType::Individual);

    expect($price->totalMinor)->toBe(1026)
        ->and($price->gatewayFeeMinor)->toBe(26);

    // And the naive form undercharges: 1000 × 1.025 = 1025 collects 999 net.
    expect($price->totalMinor)->toBeGreaterThan(1025);

    // The property behind the number: what survives the gateway's cut covers the
    // teacher and the platform in full, with nothing owed out of margin.
    $kept = (int) floor($price->totalMinor * 250 / 10_000);

    expect($price->totalMinor - $kept)
        ->toBeGreaterThanOrEqual($price->teacherRateMinor + $price->operatingFeeMinor);
});

it('rounds up, and keeps the four components summing to the total', function (): void {
    // Deliberately awkward: 333 a session, 3 credits, 2.9% plus 50 fixed.
    $price = pricingWith(bps: 290, fixed: 50, operating: 7)->compose(3, 333, ClassSessionType::Group);

    expect($price->teacherRateMinor + $price->operatingFeeMinor + $price->gatewayFeeMinor)
        ->toBe($price->totalMinor);

    $kept = (int) floor($price->totalMinor * 290 / 10_000) + 50;

    // Rounding never leaves the platform short — the direction that matters.
    expect($price->totalMinor - $kept)
        ->toBeGreaterThanOrEqual($price->teacherRateMinor + $price->operatingFeeMinor);
});

it('refuses to price at all when the gateway would keep everything', function (): void {
    expect(fn () => pricingWith(bps: 10_000)->compose(1, 100, ClassSessionType::Individual))
        ->toThrow(DomainException::class);
});

/*
| FR-021ز — no approved rate means NO PRICE, and no price means no packages.
|
| Not a default, not zero, not the last rate seen. A default price is a number
| nobody approved, charged to a student and owed to a teacher who never agreed
| to it.
*/
it('returns null for a course with no approved rate', function (): void {
    $this->mock(ApprovedRateDirectory::class)
        ->shouldReceive('approvedRateMinorForCourse')->andReturn(null);

    $package = new CreditPackage(['credits' => 4, 'session_type' => ClassSessionType::Individual]);

    expect(app(CostPlusPricing::class)->price($package, 1, now()))->toBeNull();
});
