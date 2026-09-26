<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Filament\Pages\SettlementAuditLog;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Models\ActivityEntry;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
| سجلُّ تدقيقِ التسوية — `GET /admin/settlement/audit` قائمٌ منذُ ٠١٤ ولا تقرؤه شاشة.
|
| ⚠️ **الموظّفُ يملكُ ورشةً أخرى**، وهذا ما كشفَ عيبَ القراءة: `->with('subject')`
| منطوقاً بورشةِ الموظّفِ أعادَ `subject_uuid: null` لكلِّ قرارٍ في ورشةٍ أخرى،
| فقرأَ السجلُّ «العنصر محذوف» عن فترةٍ قائمة. التجهيزةُ تُثبِتُ أنّ السياقَ ورشةُ
| الموظّفِ لا null قبلَ أن تسأل.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->teacherWorkspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية السجل']);
    [$this->officerWorkspace] = $this->createWorkspaceWithOwner(['name' => 'ورشة الموظف']);

    $this->officer = User::factory()->create(['is_super_admin' => true]);
    $this->officer->forceFill(['last_workspace_id' => $this->officerWorkspace->getKey()])->save();

    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->teacherWorkspace->getKey(),
        'user_id' => $this->teacher->getKey(),
    ]);

    $startsOn = CarbonImmutable::now()->subDays(40)->startOfDay();

    $this->period = SettlementPeriod::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $this->teacherWorkspace->getKey(),
        'teacher_profile_id' => $profile->getKey(),
        'starts_on' => $startsOn,
        'ends_on' => $startsOn->addDays(29),
        'status' => SettlementPeriodStatus::Paid,
        'units_count' => 3,
        'gross_minor' => 15_000,
        'deductions_minor' => 0,
        'net_minor' => 15_000,
        'currency' => 'QAR',
        'closed_at' => now()->subDays(5),
    ]);

    $this->entry = activity()
        ->performedOn($this->period)
        ->causedBy($this->officer)
        ->withProperties(['amount_minor' => 15_000, 'reference' => 'TRF-42', 'workspace_id' => $this->teacherWorkspace->getKey()])
        ->log('settlement.payout.executed');

    // The other context's row in the same table — it must never surface here.
    $order = Order::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $this->teacherWorkspace->getKey(),
        'user_id' => $this->teacher->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 9_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    $this->billingEntry = activity()->performedOn($order)->log('approved');

    app()->forgetInstance(WorkspaceContext::class);
});

function settlementAuditAs(User $officer, bool $api = false): void
{
    $api ? Sanctum::actingAs($officer) : test()->actingAs($officer);
    app()->forgetInstance(WorkspaceContext::class);

    expect(app(WorkspaceContext::class)->id())->toBe((int) $officer->last_workspace_id);
}

it('is a screen at all, not a file nobody discovers', function (): void {
    expect(Filament::getPanel('admin')->getPages())->toContain(SettlementAuditLog::class);
});

it('lists an act from ANOTHER workspace with its subject, its actor and its figures — and no billing row', function (): void {
    settlementAuditAs($this->officer);

    Livewire::test(SettlementAuditLog::class)
        ->loadTable()
        ->assertCanSeeTableRecords([ActivityEntry::query()->findOrFail($this->entry->getKey())])
        ->assertCanNotSeeTableRecords([ActivityEntry::query()->findOrFail($this->billingEntry->getKey())])
        ->assertTableColumnStateSet('description', 'settlement.payout.executed', $this->entry->getKey())
        ->assertTableColumnStateSet('subject_label', 'فترة تسوية', $this->entry->getKey())
        ->assertTableColumnStateSet('actor', $this->officer->name, $this->entry->getKey())
        // ⚠️ `workspace_id` is an autoincrement id and stays out, as in the API.
        ->assertTableColumnStateSet('details', 'المبلغ: 150.00 · المرجع: TRF-42', $this->entry->getKey())
        ->assertSee($this->period->uuid);
});

it('narrows by subject inside the six types, never around them', function (): void {
    settlementAuditAs($this->officer);

    Livewire::test(SettlementAuditLog::class)
        ->loadTable()
        ->filterTable('subject_type', SettlementPeriod::class)
        ->assertCanSeeTableRecords([ActivityEntry::query()->findOrFail($this->entry->getKey())])
        // A value outside the MAP is ignored, never a way to reach the billing rows.
        ->filterTable('subject_type', Order::class)
        ->assertCanNotSeeTableRecords([ActivityEntry::query()->findOrFail($this->billingEntry->getKey())]);
});

// Owner decision 2026-09-26 — granted by `2026_09_26_000500`.
it('opens to the finance officer, whose own workspace is elsewhere, on the screen and the API', function (): void {
    $finance = makePlatformStaff(Roles::FINANCE_ADMIN);
    $finance->forceFill(['last_workspace_id' => $this->officerWorkspace->getKey()])->save();

    settlementAuditAs($finance);
    expect(SettlementAuditLog::canAccess())->toBeTrue();

    Livewire::test(SettlementAuditLog::class)
        ->loadTable()
        ->assertCanSeeTableRecords([ActivityEntry::query()->findOrFail($this->entry->getKey())])
        ->assertSee($this->period->uuid);

    Sanctum::actingAs($finance);

    expect($this->getJson('/api/v1/admin/settlement/audit')->assertOk()->json('data.0.subject_uuid'))
        ->toBe($this->period->uuid);
});

it('is closed to every workspace role, the owner included', function (): void {
    $this->actingAs($this->teacher);
    app()->forgetInstance(WorkspaceContext::class);

    expect(SettlementAuditLog::canAccess())->toBeFalse();
    Livewire::test(SettlementAuditLog::class)->assertForbidden();

    Sanctum::actingAs($this->teacher);
    $this->getJson('/api/v1/admin/settlement/audit')->assertForbidden();
});

it('names the subject through the API too, for an officer whose own workspace is elsewhere', function (): void {
    settlementAuditAs($this->officer, api: true);

    $rows = $this->getJson('/api/v1/admin/settlement/audit')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['subject_type'])->toBe('period')
        ->and($rows[0]['subject_uuid'])->toBe($this->period->uuid);
});
