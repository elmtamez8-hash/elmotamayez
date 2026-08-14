<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-014 — the collection report does not grow a query per row.
|
| ⚠️ WHAT THIS TEST MEASURES, AND WHAT IT CANNOT. It measures the disappearance
| of the row-by-row join: an order and a price snapshot resolved inside the loop
| would make the statement count climb with the size of the period, which is
| exactly the period an auditor opens.
|
| It does NOT prove the composite index is used. The suite runs on SQLite, a full
| scan is ONE statement, and a counter cannot tell a scan from a seek — the first
| version of this test claimed "and no full scan", which the counter it used
| could never have seen. Index usage is guarded by review and by the migration
| that declares it; this is written here rather than left as a comfortable
| assumption.
|
| ⚠️ EQUALITY BETWEEN TWO SIZES, NEVER A CEILING. A fixed ceiling sails straight
| over an N+1 while the sample is small, and the sample in a test is always
| small. Equality is the only assertion that fails for the right reason. The
| argument is spelled out in full in `BalanceQueryBudgetTest`'s header.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    [$this->otherWorkspace, $this->otherOwner] = $this->createWorkspaceWithOwner();

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $role = Role::findOrCreate('platform-collector', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::BILLING_COLLECTION_VIEW, 'web'));

    $this->reader = $this->addWorkspaceMember($this->workspace, Roles::TENANT_OWNER);
    $this->reader->assignRole($role);
    $this->setCurrentWorkspace($this->workspace, $this->reader);
});

it('costs the same number of queries whether the period holds ten payments or two hundred', function (): void {
    Sanctum::actingAs($this->reader);

    $window = 'from='.now()->subWeek()->toDateString().'&to='.now()->toDateString();

    // ⚠️ ONE WARM-UP REQUEST FIRST. Spatie's permission cache is filled by the
    // first authorised request of the process — four extra statements that land
    // on whichever sample is measured first. Without this the test compares a
    // cold request against a warm one and reports the difference as an N+1.
    seedCollection(10);
    $this->getJson('/api/v1/admin/payments/collection?'.$window)->assertOk();

    $count = function (string $window): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        test()->getJson('/api/v1/admin/payments/collection?'.$window.'&per_page=200')->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $small = $count($window);

    // Twenty times the rows, in the same period.
    seedCollection(200);

    expect($count($window))->toBe($small);
});
