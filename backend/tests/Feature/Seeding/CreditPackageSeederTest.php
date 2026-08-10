<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Models\CreditPackage;
use Database\Seeders\CreditPackageSeeder;

/*
| A fresh install can SELL.
|
| `credit_packages` shipped empty with no screen behind it, so the student's
| purchase path was unreachable on any install that had not been hand-fed a row
| by curl — and spec 006's default mode is PREPAID_CREDITS, where a student who
| cannot buy cannot book. The catalogue is reference data in the same sense as
| the notification templates: not fixtures, but rows without which a whole
| surface renders empty and correct.
|
| Both session types are seeded and that is not clutter. `CostPlusPricing::price`
| resolves the approved rate FOR THE PACKAGE'S TYPE and returns null when there
| is none, and `ListCreditPackages` drops a null price — so a teacher approved
| for individual sessions alone shows three packages, not six. The list filters
| itself against what each teacher was actually approved to teach.
*/

it('seeds a catalogue a student can actually buy from', function (): void {
    $this->seed(CreditPackageSeeder::class);

    $active = CreditPackage::query()->where('is_active', true)->get();

    expect($active)->not->toBeEmpty()
        ->and($active->where('session_type', ClassSessionType::Individual))->not->toBeEmpty()
        ->and($active->where('session_type', ClassSessionType::Group))->not->toBeEmpty();

    // No package carries a price and none ever will: the price is per course,
    // because its input is that course's teacher's approved rate.
    expect(CreditPackage::query()->first()?->getAttributes())
        ->not->toHaveKeys(['price', 'amount', 'total_minor']);

    // Q-5 — credits do not expire at launch. A seeded validity would switch on a
    // policy the product has not sold, and `ExpireCreditLotsJob` would start
    // taking credits off people who were never told they could lose them.
    expect($active->whereNotNull('validity_days'))->toBeEmpty();
});

it('is safe to run twice', function (): void {
    // Reference seeders get re-run on every deploy. A second run that doubled
    // the catalogue would show the student each size twice, with no way to tell
    // the copies apart.
    $this->seed(CreditPackageSeeder::class);
    $first = CreditPackage::query()->count();

    $this->seed(CreditPackageSeeder::class);

    expect(CreditPackage::query()->count())->toBe($first);
});

it('leaves a package an operator retired alone on the next run', function (): void {
    // The catalogue is seeded, then OWNED by the operator. Re-seeding must not
    // undo a decision taken in the panel — the row is reference data at birth
    // and operator data for ever after.
    $this->seed(CreditPackageSeeder::class);

    $retired = CreditPackage::query()->firstOrFail();
    $retired->forceFill(['is_active' => false, 'sort_order' => 99])->save();

    $this->seed(CreditPackageSeeder::class);

    expect($retired->refresh()->is_active)->toBeFalse()
        ->and($retired->sort_order)->toBe(99);
});
