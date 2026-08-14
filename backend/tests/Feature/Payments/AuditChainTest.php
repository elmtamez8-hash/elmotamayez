<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\RecordCreditPurchase;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| FR-028 — money → order → purchase → ledger entry → lot → every session it paid
| for.
|
| The question an auditor asks is not "was this approved" but "what became of
| it", and no single table answers that.
|
| ⚠️ THE BUDGET IS AN EQUALITY BETWEEN TWO SIZES, NEVER A CEILING. A fixed ceiling
| passes straight over an N+1 as long as the sample is small — and the sample in a
| test is always small: five consumptions behind a per-row query is a handful of
| statements, which sails under any number a reviewer would write. Equality is the
| only assertion that fails for the right reason. The argument is spelled out in
| `BalanceQueryBudgetTest`'s header, and this is the same one.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    PlatformSettings::set('billing.operating_fee_minor.individual', 500);

    $this->package = CreditPackage::query()->create([
        'name' => 'حزمة',
        'credits' => 20,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $role = Role::findOrCreate('platform-auditor', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::BILLING_AUDIT_VIEW, 'web'));

    $this->auditor = $this->addWorkspaceMember($this->workspace, Roles::TENANT_OWNER);
    $this->auditor->assignRole($role);
    $this->setCurrentWorkspace($this->workspace, $this->auditor);
});

/**
 * A paid-for purchase whose credits were then spent on `$consumptions` sessions.
 *
 * @return string the payment's uuid
 */
function paidChainOf(int $consumptions): string
{
    $test = test();

    /*
    | ⚠️ A FRESH STUDENT PER CHAIN, AND THE FIRST VERSION OF THIS TEST WAS WRONG
    | WITHOUT IT. Two purchases on one balance share a draw order: the ledger
    | takes from the oldest lot first, so every consumption after the second
    | purchase came out of the FIRST lot — leaving the "long" chain with zero
    | allocations and the "short" one with eleven. The measurement was then about
    | which chain happened to own the allocations, not about how the cost grows.
    */
    $student = $test->addWorkspaceMember($test->workspace, Roles::STUDENT);

    $balance = billingBalance($test->workspace, $student, $test->course);

    $order = Order::create([
        'workspace_id' => $test->workspace->getKey(),
        'user_id' => $student->getKey(),
        'course_id' => $test->course->getKey(),
        'kind' => OrderKind::Credits,
        'amount_minor' => 110_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]);

    CreditPurchase::create([
        'credit_balance_id' => $balance->getKey(),
        'credit_package_id' => $test->package->getKey(),
        'course_id' => $test->course->getKey(),
        'workspace_id' => $test->workspace->getKey(),
        'order_id' => $order->getKey(),
        'credits' => 20,
        'teacher_rate_minor' => 5_000,
        'operating_fee_minor' => 500,
        'gateway_fee_minor' => 0,
        'total_minor' => 110_000,
        'currency' => 'QAR',
        'purchased_at' => now(),
    ]);

    $payment = PaymentTransaction::create([
        'workspace_id' => $test->workspace->getKey(),
        'order_id' => $order->getKey(),
        'provider' => 'manual',
        'amount_minor' => 110_000,
        'currency' => 'QAR',
        'status' => PaymentStatus::Captured,
        'reference' => 'REF-CHAIN-'.$order->getKey(),
    ]);

    $payment->forceFill(['captured_order_id' => $order->getKey()])->save();

    app(RecordCreditPurchase::class)->handle($order->refresh());

    // Sessions taught, each drawing from the lot the purchase opened.
    consumeCredits($balance->refresh(), $consumptions, 'chain-'.$order->getKey());

    return $payment->uuid;
}

it('follows one payment all the way to the sessions it paid for', function (): void {
    Sanctum::actingAs($this->auditor);

    $uuid = paidChainOf(3);

    $chain = $this->getJson("/api/v1/admin/payments/audit/{$uuid}")->assertOk()->json('data');

    expect($chain['payment']['uuid'])->toBe($uuid)
        ->and($chain['credits_purchased'])->toBe(20)
        // Twenty bought, three taught: the lot still holds seventeen, and the
        // three that left are named individually rather than summarised.
        ->and($chain['credits_remaining_in_lot'])->toBe(17)
        ->and($chain['consumptions'])->toHaveCount(3);

    foreach ($chain['consumptions'] as $consumption) {
        expect($consumption['entry_uuid'])->not->toBeNull()
            ->and($consumption['credits'])->toBe(1);
    }

    // And the decisions taken about it, in the same shape the list uses.
    expect($chain['trail'])->toBeArray();
});

it('follows a payment taken in a workspace the auditor is not in', function (): void {
    /*
    | ⚠️ THE CASE EVERY OTHER TEST HERE MISSES, AND IT WAS A REAL BUG. The chain
    | is platform-wide by permission, and its first query says so — but the ORDER
    | arrived through `->with('order')`, whose relation query runs Order's global
    | scope on its own. `WorkspaceContext::id()` falls back to
    | `users.last_workspace_id` for every user including a super admin, so a
    | payment taken anywhere but the reader's fallback workspace came back with a
    | null order and the whole chain answered "nothing was bought" — quietly,
    | with a 200.
    |
    | A fixture with one workspace cannot see it, which is why this one builds a
    | second and audits from the first.
    */
    [$elsewhere, $otherOwner] = $this->createWorkspaceWithOwner();

    $course = courseWithRate((int) $elsewhere->getKey(), 5000);
    $student = $this->addWorkspaceMember($elsewhere, Roles::STUDENT);

    $order = Order::create([
        'workspace_id' => $elsewhere->getKey(),
        'user_id' => $student->getKey(),
        'course_id' => $course->getKey(),
        'kind' => OrderKind::Credits,
        'amount_minor' => 110_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]);

    $payment = PaymentTransaction::create([
        'workspace_id' => $elsewhere->getKey(),
        'order_id' => $order->getKey(),
        'provider' => 'manual',
        'amount_minor' => 110_000,
        'currency' => 'QAR',
        'status' => PaymentStatus::Captured,
        'reference' => 'REF-ELSEWHERE',
    ]);

    // Back to the auditor's own workspace, which is NOT the one above.
    $this->setCurrentWorkspace($this->workspace, $this->auditor);
    Sanctum::actingAs($this->auditor);

    $chain = $this->getJson("/api/v1/admin/payments/audit/{$payment->uuid}")->assertOk()->json('data');

    expect($chain['order_uuid'])->toBe($order->uuid);
});

it('costs the same number of queries however long the chain gets', function (): void {
    Sanctum::actingAs($this->auditor);

    $short = paidChainOf(2);
    $long = paidChainOf(9);

    // ⚠️ ONE WARM-UP REQUEST FIRST. Spatie's permission cache is filled by the
    // first authorised request of the process — four extra statements that land
    // on whichever chain is measured first and on neither of the others. Without
    // this the test compares a cold request against a warm one and reports the
    // difference as an N+1.
    $this->getJson("/api/v1/admin/payments/audit/{$short}")->assertOk();

    $count = function (string $uuid): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        test()->getJson("/api/v1/admin/payments/audit/{$uuid}")->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // ⚠️ EQUAL, not "under twenty". The chain grows with how many sessions the
    // student has taken — which is precisely the student an auditor opens — so a
    // per-allocation lookup would grow the cost with the thing being examined.
    expect($count($long))->toBe($count($short));
});
