<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\SavePlan;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
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

/*
| ⛔ THE FOUR `/admin/plans` CASES LEFT WITH THEIR ROUTES — deleted 2026-09-05.
|
| `GET /admin/plans` and `PATCH /admin/plans/{uuid}/price` were a second door
| onto `PlanResource`, called by no file under `frontend/src`. `PlanPanelTest`
| twins every claim they carried: the screen opens for the platform and for
| nobody in a tenant role, it shows every teacher's plan and not just the
| officer's own fallback workspace (the two-workspace fixture that is the whole
| point of such a test), it actually WRITES the price — `price_minor` is not
| fillable, so mass assignment would silently not — and clearing the price takes
| the plan off sale.
|
| ⚠️ AND THE PANEL IS A COMPLETE TWIN BECAUSE OF ONE LINE:
| `EditPlan::handleRecordUpdate()` calls `SetPlanPrice`, so the refusal of a
| negative price and the `plan.priced` activity-log entry come with it. Had it
| written the column directly, deleting the route would have deleted the audit
| trail of every repricing on the platform.
|
| FR-030 — a live subscription's price does not move when the plan is repriced —
| is measured where it is actually decided, on the buying path in
| `SubscriptionActivationTest`: `subscriptions.price_minor` is a snapshot written
| at activation from the ORDER, and there is no query from the pricing Action to
| a subscription at all.
*/

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
