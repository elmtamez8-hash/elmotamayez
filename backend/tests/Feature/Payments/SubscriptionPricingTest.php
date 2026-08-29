<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\SavePlan;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| FR-025 (Q4) — the teacher writes the duration and the coverage, the PLATFORM
| writes the price (T091 · T097).
|
| ⚠️ THE ALLOW DIRECTION IS HERE AS WELL AS THE DENY. Laravel's policy guesser
| fails OPEN into «no policy applies», so a deny-only file passes just as well
| against a `Gate::policy()` line nobody added — the shape `taxonomy.manage`
| shipped with in 009, declared and seeded and read by no file at all.
*/
beforeEach(function (): void {
    // ⚠️ THE TEAMLESS PLATFORM ROLES LIVE IN THIS SEEDER AND NOWHERE ELSE.
    // `makePlatformStaff` grants standing; the PERMISSIONS behind that standing
    // come from `finance-admin`'s teamless row, so without this the officer holds
    // nothing and every allow-direction assertion below reads 403 — which looks
    // exactly like a policy working.
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->plan = Plan::factory()->unpriced()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->course = courseWithRate((int) $this->workspace->getKey());
});

it('lets the teacher write the duration and the coverage', function (): void {
    $plan = app(SavePlan::class)->handle($this->owner, (int) $this->workspace->getKey(), [
        'title' => 'شهري',
        'duration_days' => 30,
        'session_type' => ClassSessionType::Individual->value,
        'coverage_type' => PlanCoverage::Workspace->value,
    ]);

    expect($plan->duration_days)->toBe(30)
        ->and($plan->price_minor)->toBeNull();
});

it('refuses a price from a teacher with a SENTENCE, never in silence', function (): void {
    /*
    | ⚠️ SILENTLY DROPPING THE KEY WOULD BE WORSE THAN REFUSING IT. A teacher who
    | types 300, is told nothing, and sees the plan saved believes they set a
    | price — and finds out when no student can buy. Filtering it out of the form
    | also shapes ONE request and not the next: the panel, a seeder and any
    | importer reach this Action with no form behind them.
    */
    expect(fn () => app(SavePlan::class)->handle($this->owner, (int) $this->workspace->getKey(), [
        'title' => 'شهري',
        'duration_days' => 30,
        'session_type' => ClassSessionType::Individual->value,
        'coverage_type' => PlanCoverage::Workspace->value,
        'price_minor' => 30_000,
    ]))->toThrow(DomainException::class);
});

it('keeps `plans.price` out of every tenant role', function (): void {
    // Derived by subtraction: a constant nobody puts in a workspace role is
    // platform-level from the day it lands. `finance-admin` holding it does not
    // change that — it is teamless standing, not a workspace role.
    expect(RolePermissionMatrix::platformPermissions())->toContain(Permissions::PLANS_PRICE)
        ->and(RolePermissionMatrix::tenantPermissions())->not->toContain(Permissions::PLANS_PRICE);

    expect($this->owner->can(Permissions::PLANS_PRICE))->toBeFalse()
        ->and($this->owner->can(Permissions::PLANS_MANAGE))->toBeTrue();
});

it('refuses a teacher the pricing route and allows a platform officer', function (): void {
    Sanctum::actingAs($this->owner);

    $this->patchJson("/api/v1/admin/plans/{$this->plan->uuid}/price", ['price_minor' => 30_000])
        ->assertForbidden();

    Sanctum::actingAs(makePlatformStaff(Roles::FINANCE_ADMIN));

    $this->patchJson("/api/v1/admin/plans/{$this->plan->uuid}/price", ['price_minor' => 30_000])
        ->assertOk()
        ->assertJsonPath('data.price_minor', 30_000);
});

it('reaches a plan in a workspace the officer has nothing to do with', function (): void {
    /*
    | ⚠️ TWO WORKSPACES, OR THIS TEST PROVES NOTHING. `WorkspaceContext::id()`
    | falls back to `users.last_workspace_id` for a platform officer exactly as
    | for anybody else, so a scoped read and an unscoped one agree perfectly on a
    | single-workspace fixture — and the scoped version answers 404 for every
    | teacher but one in production. The audit chain already shipped that once.
    */
    [$other, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($other, $otherOwner);

    $elsewhere = Plan::factory()->unpriced()->create(['workspace_id' => $other->getKey()]);

    $officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $officer->forceFill(['last_workspace_id' => $this->workspace->getKey()])->save();

    Sanctum::actingAs($officer);

    $this->patchJson("/api/v1/admin/plans/{$elsewhere->uuid}/price", ['price_minor' => 12_000])
        ->assertOk();

    // And the queue shows BOTH, not just the officer's fallback workspace.
    $this->getJson('/api/v1/admin/plans')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('does not move a price that has already been agreed', function (): void {
    /*
    | FR-030. There is no query from the pricing Action to a subscription at all —
    | the snapshot on `subscriptions.price_minor` is what makes that safe, and it
    | is measured on the buying path in SubscriptionActivationTest.
    */
    $officer = makePlatformStaff(Roles::FINANCE_ADMIN);

    Sanctum::actingAs($officer);

    $this->patchJson("/api/v1/admin/plans/{$this->plan->uuid}/price", ['price_minor' => 30_000])->assertOk();
    $this->patchJson("/api/v1/admin/plans/{$this->plan->uuid}/price", ['price_minor' => 45_000])->assertOk();

    expect((int) $this->plan->refresh()->price_minor)->toBe(45_000);
});

it('takes a plan off sale when the price is cleared', function (): void {
    $officer = makePlatformStaff(Roles::FINANCE_ADMIN);

    Sanctum::actingAs($officer);

    $this->patchJson("/api/v1/admin/plans/{$this->plan->uuid}/price", ['price_minor' => 30_000])->assertOk();
    $this->patchJson("/api/v1/admin/plans/{$this->plan->uuid}/price", ['price_minor' => null])->assertOk();

    expect($this->plan->refresh()->isSellable())->toBeFalse();
});

it('refuses a coverage course that belongs to another teacher', function (): void {
    // `exists:courses,uuid` is a raw query with no global scope on it, so the
    // obvious rule answers yes for every course on the platform — and a plan
    // pointing at somebody else's course is a teacher selling a colleague's
    // material.
    [$other, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($other, $otherOwner);
    $theirs = courseWithRate((int) $other->getKey());

    $this->setCurrentWorkspace($this->workspace, $this->owner);

    expect(fn () => app(SavePlan::class)->handle($this->owner, (int) $this->workspace->getKey(), [
        'title' => 'كورس واحد',
        'duration_days' => 30,
        'session_type' => ClassSessionType::Individual->value,
        'coverage_type' => PlanCoverage::Course->value,
        'coverage_uuid' => $theirs->uuid,
    ]))->toThrow(DomainException::class);
});

it('hides an unpriced plan from the student catalogue and keeps it on the teacher\'s list', function (): void {
    // «هذه الباقة تنتظر تسعير المنصّة» is the whole reason nobody can buy it, so
    // the teacher has to see the row; the student must not be offered a month
    // that cannot be paid for.
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/manage/plans')->assertOk()->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/billing/plans?course='.$this->course->uuid)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
