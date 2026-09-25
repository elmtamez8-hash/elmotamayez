<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\CloseSettlementPeriod;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Support\SettlementWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
| The close's claim (`status: open → closed`) committed BEFORE its transaction.
| A throw inside the arithmetic then left the period `closed` with nothing
| stamped and no totals, and every retry lost its own claim — a teacher's month
| shut at zero for ever. The claim now rolls back with the failure.
|
| ⚠️ THE ASSERTION IS ON THE RETRY'S END STATE: «the first call threw» is true
| with and without the transaction.
*/
beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
});

it('leaves the period open when the close throws half-way, so a retry closes it whole', function (): void {
    $startsOn = CarbonImmutable::now()->subDays(30)->startOfDay();

    $period = SettlementPeriod::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'starts_on' => $startsOn,
        'ends_on' => $startsOn->addDays(29),
        'status' => SettlementPeriodStatus::Open,
    ]);

    $unit = TeachingUnit::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 5000,
        'delivered_at' => $startsOn->addDay(),
    ]);

    LedgerEntry::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'teaching_unit_id' => $unit->getKey(),
        'amount_minor' => 5000,
    ]);

    // `freezeTotals()` asks the window for the previous close — after the units
    // were stamped, i.e. exactly half-way.
    $window = Mockery::mock(SettlementWindow::class);
    $window->shouldReceive('lastClosed')->once()->andThrow(new RuntimeException('HALF-WAY'));

    expect(fn () => (new CloseSettlementPeriod($window))->handle($period))
        ->toThrow(RuntimeException::class, 'HALF-WAY');

    expect($period->fresh()->status)->toBe(SettlementPeriodStatus::Open)
        ->and($period->fresh()->closed_at)->toBeNull()
        ->and($unit->fresh()->settlement_period_id)->toBeNull();

    $closed = app(CloseSettlementPeriod::class)->handle($period->fresh());

    expect($closed)->not->toBeNull()
        ->and($closed->status)->toBe(SettlementPeriodStatus::Closed)
        ->and($closed->units_count)->toBe(1)
        ->and($closed->net_minor)->toBe(5000);
});
