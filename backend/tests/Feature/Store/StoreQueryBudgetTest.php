<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-016 — the store lists cost a fixed number of queries however many rows.
|
| ⚠️ AND THE FIELDS ARE ASSERTED AS WELL AS THE COST, because those two guard
| OPPOSITE mistakes. Drop `->with('course')` and there is no N+1 at all: the key
| is simply ABSENT, the page is one query CHEAPER, and a test measuring queries
| alone reports the regression as an improvement — with every product then listed
| against no course at all. Spec 010 wrote that lesson down after the officer's
| queue started listing requests with nobody's name on them.
|
| Budgets, not exact counts: something unrelated adding a query should not fail
| the build, but a per-row query must. Each is written against twice the fixture.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
});

function storeReader(string $permission): User
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($test->workspace->getKey());

    $role = Role::findOrCreate('store-keeper', 'web');
    $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));

    $user = $test->addWorkspaceMember($test->workspace, Roles::TEACHER);
    $user->assignRole($role);
    $test->setCurrentWorkspace($test->workspace, $user);

    return $user;
}

it('lists the teacher products at a flat cost, with the course name present', function (): void {
    StoreItem::factory()->count(20)->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
    ]);

    Sanctum::actingAs(storeReader(Permissions::STORE_ITEMS_MANAGE));

    // `countingQueries()` answers [count, whatever the closure returned].
    [$queries, $response] = countingQueries(fn () => $this->getJson('/api/v1/store/items')->assertOk());

    expect($queries)->toBeLessThan(20);

    // The other half of the guard: the eager load is still there.
    expect($response->json('data.0.course.title'))->not->toBeNull();
});

it('lists a buyer purchases at a flat cost, with the item title present', function (): void {
    $buyer = User::factory()->create();

    $items = StoreItem::factory()->count(20)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    foreach ($items as $item) {
        StoreOrder::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'store_item_id' => $item->getKey(),
            'buyer_user_id' => $buyer->getKey(),
        ]);
    }

    Sanctum::actingAs($buyer);

    [$queries, $response] = countingQueries(fn () => $this->getJson('/api/v1/store/purchases')->assertOk());

    expect($queries)->toBeLessThan(20);

    // ⚠️ `title`, not merely a non-empty payload. A constrained eager load that
    // omits a column returns the row with the key silently missing — the
    // `'relation:id,uuid,name'` defect that listed six screens' worth of people
    // as «».
    expect($response->json('data.0.item.title'))->not->toBeNull();
});
