<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Settlement\Actions\CloseSettlementPeriod;
use App\Modules\Settlement\Actions\RecordDeduction;
use App\Modules\Settlement\Actions\RecordTeacherPayout;
use App\Modules\Settlement\Enums\LedgerEntryType;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Jobs\CloseDueSettlementPeriodsJob;
use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeacherPayout;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Settlement\Support\SettlementWindow;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| SC-014 · SC-015 — the cycle runs twice and nothing happens twice.
|
| Both irreversible acts here are conditional UPDATEs checked by affected rows,
| never count()-then-write. And never lockForUpdate(): it is a NO-OP on SQLite,
| so a test built around it passes locally and proves nothing about the MySQL it
| will run on. That lesson was paid for in 005's seats.
|
| Queue::fake() throughout, as the phase requires: a `->delay()` runs IMMEDIATELY
| on the `sync` connection, so anything scheduled inside an Action would execute
| inside the Action that scheduled it and collapse the timeline under test.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
});

/** A period covering the days behind us, with `$units` accrued units inside it. */
function closableePeriod(int $units = 3, int $amountMinor = 5000): SettlementPeriod
{
    $startsOn = CarbonImmutable::now()->subDays(30)->startOfDay();
    $endsOn = $startsOn->addDays(29);

    $period = SettlementPeriod::factory()->create([
        'teacher_profile_id' => test()->teacher->getKey(),
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'status' => SettlementPeriodStatus::Open,
    ]);

    for ($i = 0; $i < $units; $i++) {
        $unit = TeachingUnit::factory()->create([
            'teacher_profile_id' => test()->teacher->getKey(),
            'amount_minor' => $amountMinor,
            'delivered_at' => $startsOn->addDays($i),
        ]);

        LedgerEntry::factory()->create([
            'teacher_profile_id' => test()->teacher->getKey(),
            'teaching_unit_id' => $unit->getKey(),
            'amount_minor' => $amountMinor,
        ]);
    }

    return $period;
}

it('closes once however many times it is asked', function (): void {
    $period = closableePeriod(3);

    $first = app(CloseSettlementPeriod::class)->handle($period, $this->owner);
    $second = app(CloseSettlementPeriod::class)->handle($period->fresh(), $this->owner);

    // The second call is refused by the condition on the UPDATE, not by a check
    // that read the row first and then decided.
    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($period->fresh()->status)->toBe(SettlementPeriodStatus::Closed)
        ->and($period->fresh()->units_count)->toBe(3)
        ->and($period->fresh()->gross_minor)->toBe(15_000)
        ->and($period->fresh()->net_minor)->toBe(15_000);
});

it('sweeps a unit delivered on the closing day itself', function (): void {
    $period = closableePeriod(1);

    // 23:00 on `ends_on`. `ends_on` is a DATE and `delivered_at` a timestamp, so
    // `delivered_at <= ends_on` binds midnight and drops the whole last day —
    // the boundary bug FreezePeriod::covering() already paid for once.
    $lastDay = TeachingUnit::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 7000,
        'delivered_at' => CarbonImmutable::parse($period->ends_on->toDateString())->setTime(23, 0),
    ]);

    LedgerEntry::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        // Linked to its unit, so the entry rides in on the unit's date and this
        // test measures the unit boundary rather than the entry's own.
        'teaching_unit_id' => $lastDay->getKey(),
        'amount_minor' => 7000,
    ]);

    app(CloseSettlementPeriod::class)->handle($period, $this->owner);

    expect($period->fresh()->units_count)->toBe(2)
        ->and($period->fresh()->gross_minor)->toBe(12_000);
});

it('pays once however many times it is asked', function (): void {
    $period = closableePeriod(2);
    app(CloseSettlementPeriod::class)->handle($period, $this->owner);

    $first = app(RecordTeacherPayout::class)->handle($period->fresh(), $this->owner, 'TRF-1');
    $second = app(RecordTeacherPayout::class)->handle($period->fresh(), $this->owner, 'TRF-2');

    // The unique index on settlement_period_id is the real guard — the Action's
    // own check protects the API path and nothing else, and the settlement cycle
    // is exactly the kind of thing that gets re-run.
    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(TeacherPayout::query()->count())->toBe(1)
        ->and($first->amount_minor)->toBe(10_000)
        ->and($period->fresh()->status)->toBe(SettlementPeriodStatus::Paid);

    // The payout is a NEGATIVE ledger line, so the running balance falls to zero
    // rather than the teacher staying owed what they were just paid.
    expect((int) LedgerEntry::query()->sum('amount_minor'))->toBe(0);
});

it('carries a late unit forward instead of reopening a closed period', function (): void {
    $period = closableePeriod(2);
    app(CloseSettlementPeriod::class)->handle($period, $this->owner);

    // Taught inside the closed window, accrued after it shut — the ingest that
    // released it simply finished late.
    $late = TeachingUnit::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 4000,
        'delivered_at' => CarbonImmutable::parse($period->starts_on->toDateString())->addDay(),
    ]);

    LedgerEntry::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'teaching_unit_id' => $late->getKey(),
        'amount_minor' => 4000,
    ]);

    // FR-024. The closed period's frozen totals do not move — a teacher may
    // already have been paid against them — and the unit waits, unclaimed, for
    // the next close.
    expect($period->fresh()->units_count)->toBe(2)
        ->and($period->fresh()->gross_minor)->toBe(10_000)
        ->and($period->fresh()->status)->toBe(SettlementPeriodStatus::Closed)
        ->and($late->fresh()->settlement_period_id)->toBeNull();

    $next = SettlementPeriod::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'starts_on' => CarbonImmutable::parse($period->ends_on->toDateString())->addDay(),
        'ends_on' => CarbonImmutable::parse($period->ends_on->toDateString())->addDays(30),
        'status' => SettlementPeriodStatus::Open,
    ]);

    app(CloseSettlementPeriod::class)->handle($next, $this->owner);

    expect($next->fresh()->units_count)->toBe(1)
        ->and($next->fresh()->gross_minor)->toBe(4000);
});

it('leaves a disputed unit out of the settlement', function (): void {
    $period = closableePeriod(2);

    TeachingUnit::factory()->disputed()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 9000,
        'delivered_at' => CarbonImmutable::parse($period->starts_on->toDateString()),
    ]);

    app(CloseSettlementPeriod::class)->handle($period, $this->owner);

    // FR-008. Closing over a dispute pays a claim nobody has resolved, and
    // reopening the period to take it back is forbidden — so it waits.
    expect($period->fresh()->units_count)->toBe(2)
        ->and(
            TeachingUnit::query()
                ->where('status', TeachingUnitStatus::Disputed)
                ->whereNull('settlement_period_id')
                ->count()
        )->toBe(1);
});

it('carries a negative net forward and pays nothing', function (): void {
    $period = closableePeriod(1, 5000);

    // A correction bigger than the window's earnings — one reversal of a unit
    // from a period already paid.
    app(RecordDeduction::class)->handle(
        $this->teacher,
        8000,
        'استرداد عن حصة لم تُنفَّذ في فترة سابقة',
        $this->owner,
    );

    app(CloseSettlementPeriod::class)->handle($period, $this->owner);

    expect($period->fresh()->net_minor)->toBe(-3000)
        // FR-026 — carried, not paid. The column that would hold a payout is
        // unsigned, so a negative one could not even be written.
        ->and($period->fresh()->carried_out_minor)->toBe(-3000);

    expect(fn () => app(RecordTeacherPayout::class)->handle($period->fresh(), $this->owner, 'TRF-X'))
        ->toThrow(DomainException::class);

    expect(TeacherPayout::query()->count())->toBe(0);
});

it('opens the next period carrying the previous shortfall in', function (): void {
    $period = closableePeriod(1, 5000);
    app(RecordDeduction::class)->handle($this->teacher, 8000, 'استرداد', $this->owner);
    app(CloseSettlementPeriod::class)->handle($period, $this->owner);

    $window = app(SettlementWindow::class);
    $lastClosed = $window->lastClosed((int) $this->teacher->getKey());

    // The next window opens the day after, and opens it in the red — which is
    // the whole point of carrying rather than forgiving.
    expect($lastClosed?->carried_out_minor)->toBe(-3000);

    [$startsOn] = $window->bounds((int) $this->teacher->getKey(), null, $lastClosed);

    expect($startsOn->toDateString())
        ->toBe(CarbonImmutable::parse($period->ends_on->toDateString())->addDay()->toDateString());
});

it('refuses to pay a period that is still open', function (): void {
    $period = closableePeriod(2);

    expect(fn () => app(RecordTeacherPayout::class)->handle($period, $this->owner, 'TRF-1'))
        ->toThrow(DomainException::class);
});

it('records a deduction with its reason, where the statement can read it', function (): void {
    app(RecordDeduction::class)->handle($this->teacher, 2500, 'تعويض طالب', $this->owner);

    $entry = LedgerEntry::query()->where('type', LedgerEntryType::Deduction)->sole();

    // FR-025 — the reason travels with the money. "We took 25 off" with no
    // reason is the message that becomes a support ticket.
    expect($entry->amount_minor)->toBe(-2500)
        ->and($entry->reason)->toBe('تعويض طالب')
        ->and($entry->created_by)->toBe($this->owner->getKey())
        ->and($entry->settlement_period_id)->toBeNull();
});

it('refuses to edit a ledger entry one row at a time', function (): void {
    $entry = LedgerEntry::factory()->create(['teacher_profile_id' => $this->teacher->getKey()]);

    // Closing a period stamps `settlement_period_id` with a BULK update, which
    // fires no model events by design — Eloquent retrieves no models for one.
    // That is the sanctioned writer. A per-instance save is not, for any column:
    // the append-only guarantee is what makes the balance the sum of its rows.
    expect(fn () => $entry->update(['settlement_period_id' => 1]))
        ->toThrow(RuntimeException::class);

    expect(fn () => $entry->update(['amount_minor' => 1]))
        ->toThrow(RuntimeException::class);
});

it('closes every due period the sweep finds, and leaves the current one alone', function (): void {
    $due = closableePeriod(2);

    $current = SettlementPeriod::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'starts_on' => CarbonImmutable::now()->startOfDay(),
        'ends_on' => CarbonImmutable::now()->addDays(29),
        'status' => SettlementPeriodStatus::Open,
    ]);

    app(CloseDueSettlementPeriodsJob::class)->handle(
        app(WorkspaceContext::class),
        app(CloseSettlementPeriod::class),
        app(SettlementWindow::class),
    );

    expect($due->fresh()->status)->toBe(SettlementPeriodStatus::Closed)
        // A window that has not ended yet is not due. Closing it early freezes a
        // total over days the teacher is still working.
        ->and($current->fresh()->status)->toBe(SettlementPeriodStatus::Open);
});

it('opens exactly one period for a teacher with unsettled work and none open', function (): void {
    TeachingUnit::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'delivered_at' => CarbonImmutable::now()->subDays(3),
    ]);

    $sweep = fn () => app(CloseDueSettlementPeriodsJob::class)->handle(
        app(WorkspaceContext::class),
        app(CloseSettlementPeriod::class),
        app(SettlementWindow::class),
    );

    $sweep();
    $sweep();

    // Two runs, one period. The unique index on (teacher_profile_id, starts_on)
    // is what makes that true — "look, then insert" is the definition of the
    // race, and two open periods would each claim half the teacher's units.
    expect(SettlementPeriod::query()->count())->toBe(1);
});

/*
| The two routes, and who may reach them.
|
| Closing and paying are separate permissions on purpose: they are the only
| irreversible acts in this module, and one "manage settlement" permission would
| hand both to whoever needed either.
*/

it('refuses the teacher their own close and their own payout', function (): void {
    $period = closableePeriod(2);

    // The workspace owner holds every teaching permission there is. Closing their
    // own period would let them decide when their own total stops moving.
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/admin/settlement/periods/{$period->uuid}/close")->assertForbidden();
    $this->postJson("/api/v1/admin/settlement/periods/{$period->uuid}/payouts")->assertForbidden();
});

it('lets a platform admin close and pay, and tells the teacher both times', function (): void {
    $period = closableePeriod(2);

    // Super Admin is the platform-level `users.is_super_admin` flag, never a
    // tenant role — Gate::before passes on the flag alone.
    $admin = $this->addWorkspaceMember($this->workspace, Roles::TENANT_OWNER);
    $admin->forceFill(['is_super_admin' => true])->save();
    $this->setCurrentWorkspace($this->workspace, $admin);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/admin/settlement/periods/{$period->uuid}/close")
        ->assertOk()
        ->assertJsonPath('status', 'closed')
        ->assertJsonPath('net_minor', 10_000);

    $this->postJson("/api/v1/admin/settlement/periods/{$period->uuid}/payouts", ['reference' => 'TRF-99'])
        ->assertStatus(201)
        ->assertJsonPath('amount_minor', 10_000)
        ->assertJsonPath('reference', 'TRF-99');

    // FR-029. Both notifications reach the TEACHER, not the admin who acted.
    $closed = Notification::query()
        ->where('type', NotificationType::SettlementPeriodClosed->value)
        ->where('recipient_user_id', $this->owner->getKey())
        ->first();

    $paid = Notification::query()
        ->where('type', NotificationType::TeacherPayoutIssued->value)
        ->where('recipient_user_id', $this->owner->getKey())
        ->first();

    expect($closed)->not->toBeNull()
        ->and($paid)->not->toBeNull()
        // The reference is the whole content of the second one: an amount the
        // teacher cannot match against a bank line is an amount they must ask
        // about.
        ->and($paid->body_ar)->toContain('TRF-99');
});

it('answers 422 rather than 500 when the period is closed twice through the API', function (): void {
    $period = closableePeriod(1);

    $admin = $this->addWorkspaceMember($this->workspace, Roles::TENANT_OWNER);
    $admin->forceFill(['is_super_admin' => true])->save();
    $this->setCurrentWorkspace($this->workspace, $admin);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/admin/settlement/periods/{$period->uuid}/close")->assertOk();

    // 422 with the message under a field is what fieldErrors() reads; anything
    // else lands as a banner with no context, or as a raw error the user must
    // not see.
    $this->postJson("/api/v1/admin/settlement/periods/{$period->uuid}/close")
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['status']]);
});

it('shows the teacher their own closed periods and nobody else', function (): void {
    $period = closableePeriod(2);
    app(CloseSettlementPeriod::class)->handle($period, $this->owner);

    $other = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $otherProfile = TeacherProfile::factory()->create(['user_id' => $other->getKey()]);

    SettlementPeriod::factory()->closed()->create([
        'teacher_profile_id' => $otherProfile->getKey(),
        'starts_on' => CarbonImmutable::now()->subDays(90),
        'ends_on' => CarbonImmutable::now()->subDays(61),
    ]);

    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/settlement/periods')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.uuid', $period->uuid);
});
