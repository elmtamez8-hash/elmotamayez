<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Events\SessionDelivered;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\CloseSettlementPeriod;
use App\Modules\Settlement\Actions\RecordTeacherPayout;
use App\Modules\Settlement\Actions\WriteLedgerEntry;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Listeners\AccrueUnitsOnDelivery;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeacherPayout;
use App\Modules\Settlement\Models\TeachingUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
| Two settlement writes that were several statements with nothing holding them
| together — and a failure half-way that a retry could not repair.
|
| ⚠️ EACH CASE FAILS ONCE IN THE MIDDLE AND THEN RETRIES, and the assertion is on
| the END STATE after the retry. Asserting only that the first call threw proves
| nothing: it throws with and without the transaction. What the transaction buys
| is that the first attempt leaves nothing behind, so the second one finishes the
| job — and that is what the counts below measure.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
});

/**
 * The real ledger writer, except that call number `$failOn` throws — once.
 */
function atomicLedgerFailingOn(int $failOn): WriteLedgerEntry
{
    return new class($failOn) extends WriteLedgerEntry
    {
        private int $calls = 0;

        public function __construct(private readonly int $failOn) {}

        public function handle(
            int $workspaceId,
            int $teacherProfileId,
            LedgerEntryType $type,
            int $amountMinor,
            string $currency,
            ?int $teachingUnitId = null,
            ?int $settlementPeriodId = null,
            ?int $payoutId = null,
            ?string $reason = null,
            ?int $createdBy = null,
        ): LedgerEntry {
            if (++$this->calls === $this->failOn) {
                throw new RuntimeException('ledger unavailable');
            }

            return parent::handle(
                $workspaceId,
                $teacherProfileId,
                $type,
                $amountMinor,
                $currency,
                $teachingUnitId,
                $settlementPeriodId,
                $payoutId,
                $reason,
                $createdBy,
            );
        }
    };
}

/** A closed period with a positive net, ready to be paid. */
function atomicClosedPeriod(): SettlementPeriod
{
    $startsOn = CarbonImmutable::now()->subDays(30)->startOfDay();

    $period = SettlementPeriod::factory()->create([
        'teacher_profile_id' => test()->teacher->getKey(),
        'starts_on' => $startsOn,
        'ends_on' => $startsOn->addDays(29),
        'status' => SettlementPeriodStatus::Open,
    ]);

    $unit = TeachingUnit::factory()->create([
        'teacher_profile_id' => test()->teacher->getKey(),
        'amount_minor' => 8000,
        'delivered_at' => $startsOn->addDay(),
    ]);

    LedgerEntry::factory()->create([
        'teacher_profile_id' => test()->teacher->getKey(),
        'teaching_unit_id' => $unit->getKey(),
        'amount_minor' => 8000,
    ]);

    app(CloseSettlementPeriod::class)->handle($period, test()->owner);

    return $period->fresh();
}

it('pays a period whose ledger write failed once, instead of calling it paid for ever', function (): void {
    $period = atomicClosedPeriod();

    $this->app->instance(WriteLedgerEntry::class, atomicLedgerFailingOn(1));

    expect(fn () => app(RecordTeacherPayout::class)->handle($period->fresh(), $this->owner, 'TRF-1'))
        ->toThrow(RuntimeException::class, 'ledger unavailable');

    // Nothing of the failed attempt survives — the payout row above all, which
    // is what the retry's «already paid» fast path reads.
    expect(TeacherPayout::query()->count())->toBe(0)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Payout)->count())->toBe(0)
        ->and($period->fresh()->status)->toBe(SettlementPeriodStatus::Closed);

    $this->app->forgetInstance(WriteLedgerEntry::class);

    $payout = app(RecordTeacherPayout::class)->handle($period->fresh(), $this->owner, 'TRF-1');

    expect($payout)->not->toBeNull()
        ->and(TeacherPayout::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Payout)->where('payout_id', $payout?->getKey())->count())->toBe(1)
        ->and($period->fresh()->status)->toBe(SettlementPeriodStatus::Paid)
        // The balance is the ledger's sum, payouts included: fully paid is zero.
        ->and((int) LedgerEntry::query()->where('teacher_profile_id', $this->teacher->getKey())->sum('amount_minor'))->toBe(0);
});

it('accrues every unit AND its ledger line after a delivery that failed half-way', function (): void {
    SettlementRate::factory()->group()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 2500,
    ]);

    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        // Delivered, so no unit waits on it as `PendingPackage` — each one is an
        // earning and each one must reach the ledger.
        'recording_status' => 'published',
        'delivered_at' => now(),
    ]);

    SessionBooking::factory()->count(3)->create(['class_session_id' => $session->getKey()]);

    $event = new SessionDelivered($session->refresh(), 3, []);

    // The SECOND ledger write fails: the first unit's entry would be written and
    // stranded beside two units with none.
    $this->app->instance(WriteLedgerEntry::class, atomicLedgerFailingOn(2));

    expect(fn () => app(AccrueUnitsOnDelivery::class)->handle($event))
        ->toThrow(RuntimeException::class, 'ledger unavailable');

    expect(TeachingUnit::query()->where('class_session_id', $session->getKey())->count())->toBe(0)
        ->and(LedgerEntry::query()->whereNotNull('teaching_unit_id')->count())->toBe(0);

    $this->app->forgetInstance(WriteLedgerEntry::class);

    // The retry a queued listener gets.
    app(AccrueUnitsOnDelivery::class)->handle($event);

    $units = TeachingUnit::query()->where('class_session_id', $session->getKey())->get();

    expect($units)->toHaveCount(3)
        ->and(LedgerEntry::query()->whereIn('teaching_unit_id', $units->modelKeys())->count())->toBe(3)
        ->and((int) LedgerEntry::query()->whereIn('teaching_unit_id', $units->modelKeys())->sum('amount_minor'))->toBe(7500);
});
