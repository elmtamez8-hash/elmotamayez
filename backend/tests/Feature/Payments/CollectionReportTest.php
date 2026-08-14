<?php

declare(strict_types=1);

use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-010 — the report's totals match the source with a difference of zero, over
| ten thousand payments.
|
| ⚠️ TWO WORKSPACES, AND THAT IS THE WHOLE POINT OF THE FIXTURE. The report is
| platform-wide by permission but `payment_transactions` carries
| `BelongsToWorkspace`, and `WorkspaceContext::id()` falls back to
| `users.last_workspace_id` for every user INCLUDING a super admin. A report left
| scoped would show one teacher's money as the platform's total — and on a
| one-workspace fixture it would agree with the source perfectly while doing it.
|
| ⚠️ AND THE EXPECTED TOTALS ARE COMPUTED FROM THE DATABASE, NOT FROM THE
| CONSTANTS THE SEEDER USED. A test that asserts against the numbers it typed is
| a test of its own arithmetic; asking the table what it holds is the comparison
| FR-032 actually requires.
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

it('matches the source with a difference of zero over ten thousand payments', function (): void {
    seedCollection(10_000);

    Sanctum::actingAs($this->reader);

    $summary = $this->getJson('/api/v1/admin/payments/collection?from='.now()->subWeek()->toDateString().'&to='.now()->toDateString())
        ->assertOk()
        ->json('data');

    // The source, asked directly. Both workspaces, because the report is the
    // platform's and the fixture put half the money in the other one.
    $expected = (int) DB::table('payment_transactions')->sum('amount_minor');

    expect(collectionTotal($summary['totals'], null))->toBe($expected);

    // And every breakdown sums to the same number — a marginal of a joint
    // grouping is exact, and if the three ever disagree the grouping is wrong.
    foreach (['by_method', 'by_status', 'by_source'] as $dimension) {
        expect((int) array_sum(array_column($summary[$dimension], 'amount_minor')))->toBe($expected);
    }

    // The narrowing agrees with the source too, one dimension at a time.
    expect(collectionTotal($summary['by_status'], PaymentStatus::Captured->value))
        ->toBe((int) DB::table('payment_transactions')->where('status', PaymentStatus::Captured->value)->sum('amount_minor'))
        ->and(collectionTotal($summary['by_method'], PaymentMethod::Gateway->value))
        ->toBe((int) DB::table('payment_transactions')->where('method', PaymentMethod::Gateway->value)->sum('amount_minor'))
        ->and(collectionTotal($summary['by_source'], OrderKind::Credits->value))
        ->toBe((int) DB::table('orders')->where('kind', OrderKind::Credits->value)->sum('amount_minor'));
});

it('counts both workspaces, which a scoped report would not', function (): void {
    seedCollection(20);

    Sanctum::actingAs($this->reader);

    $rows = $this->getJson('/api/v1/admin/payments/collection?from='.now()->subWeek()->toDateString().'&to='.now()->toDateString())
        ->assertOk()
        ->json('rows.data');

    // Every row resolved its order — a bare `->with('order')` would return null
    // for the half of them that live in the workspace this reader is not in.
    foreach ($rows as $row) {
        expect($row['order_uuid'])->not->toBeNull()
            ->and($row['student_uuid'])->not->toBeNull();
    }

    $students = array_unique(array_column($rows, 'student_uuid'));

    expect(count($students))->toBe(2);
});

it('holds the period at both ends, and does not lose the closing day', function (): void {
    seedCollection(4);

    // One payment at eleven at night on the last day of the window — the row a
    // `<= '2026-08-31'` upper bound drops silently, and the row nobody notices
    // is missing because the total is merely smaller.
    $late = now()->subDay()->setTime(23, 30);

    DB::table('payment_transactions')->insert([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $this->workspace->getKey(),
        'order_id' => (int) DB::table('orders')->value('id'),
        'provider' => 'manual',
        'amount_minor' => 777,
        'currency' => 'QAR',
        'status' => PaymentStatus::Captured->value,
        'method' => PaymentMethod::Gateway->value,
        'reference' => 'REF-LATE',
        'created_at' => $late,
        'updated_at' => $late,
    ]);

    Sanctum::actingAs($this->reader);

    // Two windows differing in ONE thing: whether the closing day is the day
    // that late payment landed on.
    $through = $this->getJson('/api/v1/admin/payments/collection?from='.now()->subDays(4)->toDateString().'&to='.now()->subDay()->toDateString())
        ->assertOk()->json('data.totals');

    $stoppingShort = $this->getJson('/api/v1/admin/payments/collection?from='.now()->subDays(4)->toDateString().'&to='.now()->subDays(2)->toDateString())
        ->assertOk()->json('data.totals');

    // Exactly 777 apart. A `<= ends_on` upper bound binds midnight and makes
    // these two identical — the failure that reads as a quiet morning.
    expect(collectionTotal($through, null) - collectionTotal($stoppingShort, null))->toBe(777);
});

it('exports the same rows the screen shows, under the same filter', function (): void {
    seedCollection(30);

    Sanctum::actingAs($this->reader);

    $query = 'from='.now()->subWeek()->toDateString().'&to='.now()->toDateString().'&status='.PaymentStatus::Captured->value;

    $onScreen = $this->getJson('/api/v1/admin/payments/collection?'.$query)->assertOk()->json('rows.meta.total');

    $csv = $this->get('/api/v1/admin/payments/collection/export?'.$query)
        ->assertOk()
        ->streamedContent();

    // Header line plus one per row, and the filter honoured on both sides: an
    // export that rebuilt its own query is exactly where a clause goes missing.
    $lines = array_filter(explode("\n", trim($csv)));

    expect(count($lines))->toBe($onScreen + 1)
        ->and($lines[0])->toContain('amount_minor');
});
