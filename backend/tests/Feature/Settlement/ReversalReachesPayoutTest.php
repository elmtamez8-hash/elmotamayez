<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\CloseSettlementPeriod;
use App\Modules\Settlement\Actions\ReverseTeachingUnit;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeachingUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
| FR-006 reaching the MONEY — a correction has to reduce what the teacher is paid.
|
| ⚠️ IT DID NOT. The reversal row is written `status = reversed` and its negative
| ledger line hangs off the REVERSAL row's id, so a close — which claimed
| `accrued` units and the entries of the units it claimed — never took either.
| The −amount sat unstamped for ever and the teacher was paid the hour in full.
| `UnitReversalTest` could not see it: it asserts the ledger SUM, which was always
| right, and never closes a period.
|
| The three cases: reversed inside the same window (nets to zero); reversed after
| the original was already paid (the next close carries the −amount); and the
| guard that keeps a correction travelling with an original not yet paid.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
});

/** A period of thirty days starting `$daysAgo` days back. */
function reversalWindow(int $daysAgo): SettlementPeriod
{
    $startsOn = CarbonImmutable::now()->subDays($daysAgo)->startOfDay();

    return SettlementPeriod::factory()->create([
        'teacher_profile_id' => test()->teacher->getKey(),
        'starts_on' => $startsOn,
        'ends_on' => $startsOn->addDays(29),
        'status' => SettlementPeriodStatus::Open,
    ]);
}

/** An accrued unit WITH its ledger line, as the accrual listener would leave it. */
function reversalOriginal(CarbonImmutable $deliveredAt, TeachingUnitStatus $status = TeachingUnitStatus::Accrued): TeachingUnit
{
    $unit = TeachingUnit::factory()->create([
        'teacher_profile_id' => test()->teacher->getKey(),
        'amount_minor' => 5000,
        'status' => $status,
        'delivered_at' => $deliveredAt,
    ]);

    // A pending unit has no ledger line yet — one is written only on release.
    if ($status === TeachingUnitStatus::Accrued) {
        LedgerEntry::factory()->create([
            'teacher_profile_id' => test()->teacher->getKey(),
            'teaching_unit_id' => $unit->getKey(),
            'amount_minor' => 5000,
        ]);
    }

    return $unit;
}

it('nets a unit reversed inside the window it is closed in', function (): void {
    $period = reversalWindow(30);
    $original = reversalOriginal(CarbonImmutable::parse($period->starts_on->toDateString())->addDays(2));

    $reversal = app(ReverseTeachingUnit::class)->handle($original, 'وحدة نشأت خطأً', $this->owner);

    app(CloseSettlementPeriod::class)->handle($period, $this->owner);

    $closed = $period->fresh();

    expect($closed->net_minor)->toBe(0)
        ->and($closed->gross_minor)->toBe(5000)
        ->and($closed->deductions_minor)->toBe(-5000)
        // One hour taught. The correction is money, not a second session.
        ->and($closed->units_count)->toBe(1)
        ->and($reversal->fresh()->settlement_period_id)->toBe($period->getKey())
        // The status survives the claim: it is what the statement reads to keep
        // a correction out of its session counts.
        ->and($reversal->fresh()->status)->toBe(TeachingUnitStatus::Reversed)
        ->and(LedgerEntry::query()->whereNull('settlement_period_id')->count())->toBe(0);
});

it('takes a reversal of an hour already paid off the NEXT close', function (): void {
    $first = reversalWindow(60);
    $original = reversalOriginal(CarbonImmutable::parse($first->starts_on->toDateString())->addDays(2));

    app(CloseSettlementPeriod::class)->handle($first, $this->owner);

    expect($first->fresh()->net_minor)->toBe(5000);

    // The dispute resolves a week later. Its `delivered_at` is the original's —
    // inside the FIRST window — so a date filter on it would drop it from every
    // window that is still open, which is exactly the case that matters.
    $reversal = app(ReverseTeachingUnit::class)->handle($original->fresh(), 'نزاع حُسم لصالح الطالب', $this->owner);

    $second = reversalWindow(30);

    app(CloseSettlementPeriod::class)->handle($second, $this->owner);

    expect($second->fresh()->net_minor)->toBe(-5000)
        ->and($second->fresh()->units_count)->toBe(0)
        // FR-026 — the shortfall travels on rather than being forgiven.
        ->and($second->fresh()->carried_out_minor)->toBe(-5000)
        ->and($reversal->fresh()->settlement_period_id)->toBe($second->getKey())
        // The first period is frozen and stays exactly as it was paid.
        ->and($first->fresh()->net_minor)->toBe(5000);
});

it('keeps a correction with an original that has not been paid yet', function (): void {
    $period = reversalWindow(30);

    // Still waiting for its package: no ledger line, not claimable by a close.
    $original = reversalOriginal(
        CarbonImmutable::parse($period->starts_on->toDateString())->addDays(2),
        TeachingUnitStatus::PendingPackage,
    );

    $reversal = app(ReverseTeachingUnit::class)->handle($original, 'استرداد قبل وصول التسجيل', $this->owner);

    app(CloseSettlementPeriod::class)->handle($period, $this->owner);

    // Claimed now, the −5000 would be taken off this window and the +5000 paid
    // in a later one — a shortfall carried between them that nothing owed.
    expect($period->fresh()->net_minor)->toBe(0)
        ->and($reversal->fresh()->settlement_period_id)->toBeNull();
});
