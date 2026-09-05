<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Models\CreditReconciliationRun;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| ⛔ THE NIGHTLY CREDIT RECONCILIATION HAD NO READER.
|
| `ReconcileCreditBalancesJob` runs at 04:45 and writes `credit_reconciliation_runs`
| — three invariants over the fastest-growing tables of the billing phase, and two
| of them see what the ledger cannot: a delivered session that was never charged
| leaves the balance and its entries in perfect agreement, because the same path
| writes both inside one transaction. Its reader's own docblock says «a
| reconciliation nobody notices has stopped is a reconciliation that is not
| happening» — and no file under `frontend/src` called the route, so for four
| months nobody could notice either way.
|
| Who may open it is asserted in `CollectionAccessTest`, beside its payments twin
| and under the same five cases. This file is about WHAT it answers.
*/

/**
 * A reader holding exactly one platform permission — the spelling
 * `CollectionAccessTest` already uses, and it needs every line of it.
 *
 * ⚠️ spatie puts `team_id` INSIDE `model_has_roles`' primary key and forbids
 * NULL, so a role cannot be assigned to anybody until a team id is set — the
 * same constraint that made `platform_staff` a table rather than a role. And the
 * class named here is spatie's `Role`, not `Tenancy\Models\Role`, which throws
 * on a platform permission reaching a role that has a team.
 */
function collectionReader(string $permission = Permissions::BILLING_COLLECTION_VIEW): User
{
    $test = test();

    [$workspace] = $test->createWorkspaceWithOwner();

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());

    $role = Role::findOrCreate('platform-reader-'.md5($permission), 'web');
    $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));

    $reader = $test->addWorkspaceMember($workspace, Roles::TENANT_OWNER);
    $reader->assignRole($role);
    $test->setCurrentWorkspace($workspace, $reader);

    return $reader->refresh();
}

it('answers a null run rather than an empty one when the sweep has never run', function (): void {
    /*
    | «Nothing found» and «nothing has run» are the same empty list, and only one
    | of them is reassuring. An empty `findings` array here would be a reassurance
    | the screen has no way to detect as false.
    */
    Sanctum::actingAs(collectionReader());

    $this->getJson('/api/v1/admin/billing/reconciliation')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('reports what the last run checked and what it found', function (): void {
    CreditReconciliationRun::query()->create([
        'ran_at' => now()->subHours(3),
        'balances_checked' => 412,
        'sessions_checked' => 1908,
        'findings_count' => 2,
        'findings' => [
            ['check' => 'ledger_sum', 'workspace_id' => 3, 'credit_balance_id' => 9, 'student_user_id' => 44, 'expected' => 5, 'actual' => 4],
            ['check' => 'session_seats', 'workspace_id' => 3, 'class_session_id' => 77, 'expected' => 5, 'actual' => 3],
        ],
    ]);

    Sanctum::actingAs(collectionReader());

    $this->getJson('/api/v1/admin/billing/reconciliation')
        ->assertOk()
        ->assertJsonPath('data.balances_checked', 412)
        ->assertJsonPath('data.sessions_checked', 1908)
        ->assertJsonPath('data.findings_count', 2)
        ->assertJsonPath('data.findings.0.check', 'ledger_sum')
        ->assertJsonPath('data.findings.1.class_session_id', 77);
});

it('answers the LATEST run and not the first one it finds', function (): void {
    // `latest('ran_at')`, and the rows are inserted oldest-last on purpose: an
    // `orderBy` dropped from that query returns insertion order, which on this
    // fixture is the stale run — and a stale report is indistinguishable from a
    // current one on screen.
    CreditReconciliationRun::query()->create([
        'ran_at' => now()->subDay(),
        'balances_checked' => 1,
        'sessions_checked' => 1,
        'findings_count' => 9,
        'findings' => [],
    ]);

    CreditReconciliationRun::query()->create([
        'ran_at' => now()->subMinutes(10),
        'balances_checked' => 2,
        'sessions_checked' => 2,
        'findings_count' => 0,
        'findings' => [],
    ]);

    Sanctum::actingAs(collectionReader());

    $this->getJson('/api/v1/admin/billing/reconciliation')
        ->assertOk()
        ->assertJsonPath('data.findings_count', 0)
        ->assertJsonPath('data.balances_checked', 2);
});

it('keeps the true total beside a capped sample', function (): void {
    /*
    | The job stores 200 findings at most and `findings_count` is always the real
    | number. A screen showing three rows under a count of nine thousand is
    | telling the truth about both — but only if the count survives the trip, so
    | it is asserted here rather than assumed from the column name.
    */
    CreditReconciliationRun::query()->create([
        'ran_at' => now(),
        'balances_checked' => 10,
        'sessions_checked' => 10,
        'findings_count' => 9000,
        'findings' => [
            ['check' => 'lot_remainder', 'workspace_id' => 1, 'credit_balance_id' => 2, 'student_user_id' => 3, 'expected' => 1, 'actual' => 0],
        ],
    ]);

    Sanctum::actingAs(collectionReader());

    $response = $this->getJson('/api/v1/admin/billing/reconciliation')->assertOk();

    expect($response->json('data.findings_count'))->toBe(9000)
        ->and($response->json('data.findings'))->toHaveCount(1);
});

it('refuses the pricing permission that used to be its only key', function (): void {
    // The removal probe written as a case: before 2026-09-05 this was the ONLY
    // permission that opened the route, and the collection reader above could not.
    Sanctum::actingAs(collectionReader(Permissions::BILLING_PRICING_MANAGE));

    $this->getJson('/api/v1/admin/billing/reconciliation')->assertForbidden();
});
