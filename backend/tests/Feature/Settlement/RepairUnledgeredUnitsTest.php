<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeachingUnit;

/*
| Units that were accrued with no ledger line behind them, before accrual became
| one transaction — and the command that writes the missing line.
|
| ⚠️ There is NO unique index on `ledger_entries.teaching_unit_id`, so the «run
| twice» case is the whole idempotency guarantee: delete the `NOT EXISTS` from the
| command and it fails with two entries for one hour.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
});

/** @param array<string, mixed> $attributes */
function unledgeredUnit(TeacherProfile $teacher, array $attributes = []): TeachingUnit
{
    return TeachingUnit::factory()->create(array_merge([
        'teacher_profile_id' => $teacher->getKey(),
        'amount_minor' => 5000,
        'accrued_at' => now()->subHour(),
    ], $attributes));
}

function unledgeredEntryCount(TeachingUnit $unit): int
{
    return LedgerEntry::query()
        ->withoutWorkspaceScope()
        ->where('teaching_unit_id', $unit->getKey())
        ->count();
}

it('writes exactly one entry for an accrued unit that has none, and nothing on a second run', function (): void {
    $unit = unledgeredUnit($this->teacher);

    $this->artisan('settlement:repair-unledgered-units')->assertSuccessful();

    expect(unledgeredEntryCount($unit))->toBe(1);

    $entry = LedgerEntry::query()->withoutWorkspaceScope()->where('teaching_unit_id', $unit->getKey())->sole();
    expect($entry->type)->toBe(LedgerEntryType::Unit)
        ->and($entry->amount_minor)->toBe(5000)
        ->and((int) $entry->workspace_id)->toBe((int) $unit->workspace_id)
        ->and((int) $entry->teacher_profile_id)->toBe((int) $this->teacher->getKey())
        ->and($entry->settlement_period_id)->toBeNull();

    $this->artisan('settlement:repair-unledgered-units')->assertSuccessful();

    expect(unledgeredEntryCount($unit))->toBe(1)
        ->and(LedgerEntry::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('leaves a unit that already has its entry untouched', function (): void {
    $unit = unledgeredUnit($this->teacher);

    LedgerEntry::factory()->create([
        'workspace_id' => $unit->workspace_id,
        'teacher_profile_id' => $this->teacher->getKey(),
        'type' => LedgerEntryType::Unit,
        'amount_minor' => 5000,
        'currency' => 'QAR',
        'teaching_unit_id' => $unit->getKey(),
    ]);

    $this->artisan('settlement:repair-unledgered-units')->assertSuccessful();

    expect(unledgeredEntryCount($unit))->toBe(1)
        ->and(LedgerEntry::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('gives a reversal its own negative entry keyed by its own id, without touching the original', function (): void {
    $original = unledgeredUnit($this->teacher);

    LedgerEntry::factory()->create([
        'workspace_id' => $original->workspace_id,
        'teacher_profile_id' => $this->teacher->getKey(),
        'type' => LedgerEntryType::Unit,
        'amount_minor' => 5000,
        'currency' => 'QAR',
        'teaching_unit_id' => $original->getKey(),
    ]);

    $reversal = unledgeredUnit($this->teacher, [
        'amount_minor' => -5000,
        'status' => TeachingUnitStatus::Reversed,
        'reversal_of_id' => $original->getKey(),
        'reversal_reason' => 'تصحيح',
    ]);

    $this->artisan('settlement:repair-unledgered-units')->assertSuccessful();

    expect(unledgeredEntryCount($original))->toBe(1)
        ->and(unledgeredEntryCount($reversal))->toBe(1);

    $entry = LedgerEntry::query()->withoutWorkspaceScope()->where('teaching_unit_id', $reversal->getKey())->sole();
    expect($entry->type)->toBe(LedgerEntryType::Reversal)
        ->and($entry->amount_minor)->toBe(-5000);
});

it('does not write for units that have no entry by design, or that may still be mid-write', function (): void {
    $zero = unledgeredUnit($this->teacher, ['amount_minor' => 0]);
    $pending = TeachingUnit::factory()->pending()->create(['teacher_profile_id' => $this->teacher->getKey()]);
    $disputed = unledgeredUnit($this->teacher, ['status' => TeachingUnitStatus::Disputed]);
    // Accrued a moment ago: a live writer may be between its two statements.
    $young = unledgeredUnit($this->teacher, ['accrued_at' => now()]);

    $this->artisan('settlement:repair-unledgered-units')->assertSuccessful();

    foreach ([$zero, $pending, $disputed, $young] as $unit) {
        expect(unledgeredEntryCount($unit))->toBe(0);
    }
});

it('reports a unit already inside a closed period and writes nothing for it', function (): void {
    $period = SettlementPeriod::factory()->closed()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
    ]);

    $settled = unledgeredUnit($this->teacher, [
        'status' => TeachingUnitStatus::Settled,
        'settlement_period_id' => $period->getKey(),
        'settled_at' => now(),
    ]);

    $this->artisan('settlement:repair-unledgered-units')
        ->expectsOutputToContain('داخل فترة مُغلقة (للتقرير فقط، لا تُكتب): 1')
        ->assertSuccessful();

    expect(unledgeredEntryCount($settled))->toBe(0);
});

it('only counts on a dry run', function (): void {
    $unit = unledgeredUnit($this->teacher);

    $this->artisan('settlement:repair-unledgered-units', ['--dry-run' => true])
        ->expectsOutputToContain('قابلة للإصلاح: 1')
        ->assertSuccessful();

    expect(unledgeredEntryCount($unit))->toBe(0);
});
