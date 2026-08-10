<?php

declare(strict_types=1);

use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\ExamModeWindow;
use App\Modules\Payments\Support\WithholdingReader;
use Database\Seeders\ScenarioSeeder;

/*
| The demo data has to RUN, and the billing screens have to have something on
| them (T162).
|
| A seeder is the one file nothing else exercises: it is not reached by a request,
| a job or another test, so it breaks silently and stays broken until someone
| resets their database — which is the moment they least want to debug it.
|
| What is asserted is the shape the screens need, not a row count: an empty
| balance list and a balance list where nothing is withheld both render as
| "everything is fine", and only one of them is true.
*/

it('seeds every billing state the screens have to render', function (): void {
    $this->seed(ScenarioSeeder::class);

    $balances = CreditBalance::query()->withoutWorkspaceScope()->get();

    expect($balances)->not->toBeEmpty()
        ->and(CreditPackage::query()->where('is_active', true)->count())->toBeGreaterThan(0)
        ->and(ExamModeWindow::query()->withoutWorkspaceScope()->covering(now())->exists())->toBeTrue();

    $stamped = app(WithholdingReader::class)->stamp($balances);

    // The three states, each of which renders differently. A seed of healthy
    // balances alone leaves the withheld badge, the 402 and the guardian notice
    // with nothing to show.
    expect($stamped->contains(fn (CreditBalance $b): bool => $b->remaining_credits > 3))->toBeTrue()
        ->and($stamped->contains(fn (CreditBalance $b): bool => $b->remaining_credits < 0))->toBeTrue()
        ->and($stamped->contains(fn (CreditBalance $b): bool => (bool) $b->getAttribute('is_withheld')))->toBeTrue();
});
