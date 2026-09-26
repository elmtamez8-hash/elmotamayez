<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\SettlementPeriodStatus;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeacherPayout;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| The settlement money routes carry `2fa.required`, and the finance officer's
| grant reaches an EXISTING database (owner decision 2026-09-26).
|
| ⚠️ THE PANEL ASKED `TwoFactorMandate` BY HAND AND THE API ASKED NOTHING. Three
| routes reach the same Actions as the /admin buttons — correct a unit, close a
| period, pay it — and an officer past their deadline was refused on the screen
| and waved through on the API.
|
| ⚠️ The refusal is asserted by its `code`, not by a bare 403: the policy also
| answers 403, and a test green on the wrong refusal proves nothing about the
| middleware. The ROW is asserted too — a refusal after the Action ran refused
| nothing.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية المسارات']);

    app(WorkspaceContext::class)->forWorkspace($this->workspace, function (): void {
        $this->profile = TeacherProfile::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->teacher->getKey(),
        ]);

        $this->unit = TeachingUnit::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'teacher_profile_id' => $this->profile->getKey(),
            'class_session_id' => ClassSession::factory()->create(['workspace_id' => $this->workspace->getKey()])->getKey(),
            'amount_minor' => 4_000,
        ]);

        $period = fn (SettlementPeriodStatus $status, int $daysAgo): SettlementPeriod => SettlementPeriod::query()
            ->withoutWorkspaceScope()
            ->create([
                'workspace_id' => $this->workspace->getKey(),
                'teacher_profile_id' => $this->profile->getKey(),
                'starts_on' => CarbonImmutable::now()->subDays($daysAgo)->startOfDay(),
                'ends_on' => CarbonImmutable::now()->subDays($daysAgo - 29)->startOfDay(),
                'status' => $status,
                'units_count' => 1,
                'gross_minor' => 4_000,
                'deductions_minor' => 0,
                'net_minor' => 4_000,
                'currency' => 'QAR',
                'closed_at' => $status === SettlementPeriodStatus::Closed ? now()->subDay() : null,
            ]);

        $this->openPeriod = $period(SettlementPeriodStatus::Open, 40);
        $this->closedPeriod = $period(SettlementPeriodStatus::Closed, 80);
    });

    // In the ENTITIES' workspace: the route bindings are scoped, and this test
    // is about the second factor, not about the binding.
    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $this->officer->forceFill(['last_workspace_id' => $this->workspace->getKey()])->save();

    $this->officer->securitySettings()->updateOrCreate([], [
        'two_factor_required_at' => CarbonImmutable::now()->subDay(),
    ]);

    Sanctum::actingAs($this->officer->refresh());
    app()->forgetInstance(WorkspaceContext::class);
});

it('refuses a lapsed officer on the unit reversal and writes nothing', function (): void {
    $this->postJson("/api/v1/admin/settlement/units/{$this->unit->uuid}/reverse", ['reason' => 'نزاع حُسم'])
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required');

    expect(TeachingUnit::query()->withoutWorkspaceScope()->where('reversal_of_id', $this->unit->getKey())->exists())->toBeFalse();
});

it('refuses a lapsed officer on the period close and leaves it open', function (): void {
    $this->postJson("/api/v1/admin/settlement/periods/{$this->openPeriod->uuid}/close")
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required');

    expect(SettlementPeriod::query()->withoutWorkspaceScope()->findOrFail($this->openPeriod->getKey())->status)
        ->toBe(SettlementPeriodStatus::Open);
});

it('refuses a lapsed officer on the payout and pays nothing', function (): void {
    $this->postJson("/api/v1/admin/settlement/periods/{$this->closedPeriod->uuid}/payouts", ['reference' => 'TRF-1'])
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required');

    expect(TeacherPayout::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('lets the same officer through once the mandate is met', function (): void {
    $this->officer->securitySettings()->update(['two_factor_required_at' => null]);
    Sanctum::actingAs($this->officer->refresh());

    $this->postJson("/api/v1/admin/settlement/units/{$this->unit->uuid}/reverse", ['reason' => 'نزاع حُسم'])
        ->assertCreated();
});

it('grants both permissions to an EXISTING finance-admin row and to no workspace role', function (): void {
    $financeRole = DB::table('roles')->whereNull('team_id')->where('name', Roles::FINANCE_ADMIN)->value('id');
    $granted = DB::table('permissions')
        ->whereIn('name', [Permissions::SETTLEMENT_AUDIT_VIEW, Permissions::SETTLEMENT_PERIOD_MANAGE])
        ->pluck('id')
        ->all();

    // As a database seeded before this release: the role without the grant,
    // and a stray tenant row holding one of them.
    DB::table('role_has_permissions')->where('role_id', $financeRole)->whereIn('permission_id', $granted)->delete();

    $tenantRole = DB::table('roles')->whereNotNull('team_id')->where('team_id', $this->workspace->getKey())->value('id');
    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $granted[0], 'role_id' => $tenantRole]);

    $migration = require base_path('app/Modules/Tenancy/Database/Migrations/2026_09_26_000500_grant_settlement_audit_and_correction_to_finance_admin.php');
    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(DB::table('role_has_permissions')->where('role_id', $financeRole)->whereIn('permission_id', $granted)->count())->toBe(2)
        ->and(DB::table('role_has_permissions')
            ->whereIn('permission_id', $granted)
            ->whereIn('role_id', DB::table('roles')->whereNotNull('team_id')->pluck('id'))
            ->count())->toBe(0);

    // Idempotent: a second run changes nothing.
    $migration->up();

    expect(DB::table('role_has_permissions')->where('role_id', $financeRole)->whereIn('permission_id', $granted)->count())->toBe(2);
});

it('keeps both permissions out of every workspace role in the matrix', function (): void {
    expect(RolePermissionMatrix::platformPermissions())
        ->toContain(Permissions::SETTLEMENT_AUDIT_VIEW)
        ->toContain(Permissions::SETTLEMENT_PERIOD_MANAGE);

    expect(User::query()->find($this->teacher->getKey())?->can(Permissions::SETTLEMENT_PERIOD_MANAGE))->toBeFalse();
});
