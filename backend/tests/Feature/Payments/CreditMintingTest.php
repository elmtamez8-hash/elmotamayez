<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\AdjustCredits;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Q-4 · FR-018 — the teacher is not the seller, and cannot mint what they will
| be paid out of.
|
| PAYMENTS_APPROVE lets a teacher approve a COURSE order, and it sits in the
| teacher role array. Left to cover credit orders too, the payee would be
| approving a transfer that never happened — and because billing and settlement
| are deliberately isolated contexts, nothing on the settlement side could ever
| surface it. The one thing standing between those two facts is the branch in
| OrderPolicy::approve, so it gets a test that fails if the branch is removed.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();

    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->package = CreditPackage::query()->create([
        'name' => 'حزمة',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
    ]);
});

/** A teacher holding PAYMENTS_APPROVE — everything a course order needs. */
function payingTeacher(): User
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($test->workspace->getKey());

    $role = Role::findOrCreate('paying-teacher', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::PAYMENTS_APPROVE, 'web'));

    $teacher = $test->addWorkspaceMember($test->workspace, Roles::TEACHER);
    $teacher->assignRole($role);
    $test->setCurrentWorkspace($test->workspace, $teacher);

    return $teacher;
}

it('refuses a teacher the approval of a credit order, and mints nothing', function (): void {
    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/billing/purchases', [
        'course' => $this->course->uuid,
        'package' => $this->package->uuid,
    ])->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()->firstOrFail();

    Sanctum::actingAs(payingTeacher());

    $this->postJson("/api/v1/orders/{$order->uuid}/approve")->assertForbidden();

    // Zero entries, not merely an unchanged total: an entry written and then
    // compensated would leave the same balance and a ledger that says a teacher
    // credited themselves.
    expect(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(billingBalance($this->workspace, $this->student, $this->course)->remaining_credits)->toBe(0)
        // And the purchase row survives, still pending. Refusing the approval
        // must not destroy the price snapshot the student is owed.
        ->and(CreditPurchase::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and($order->refresh()->status)->toBe('pending');
});

it('refuses a teacher a bonus grant and a limit raise', function (): void {
    $teacher = payingTeacher();
    $balance = billingBalance($this->workspace, $this->student, $this->course)->refresh();

    // The other two doors into a balance. AdjustCredits is the only one that
    // exists as code, and both are platform permissions no tenant role holds —
    // asserted through the Gate, because that is what any future endpoint will
    // ask before it acts.
    expect($teacher->can(Permissions::BILLING_PURCHASE_APPROVE))->toBeFalse()
        ->and($teacher->can(Permissions::BILLING_CREDITS_ADJUST))->toBeFalse()
        ->and($teacher->can(Permissions::BILLING_LIMIT_MANAGE))->toBeFalse();

    // And nothing about holding PAYMENTS_APPROVE moves a credit on its own.
    expect(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and($balance->remaining_credits)->toBe(0);
});

it('mints exactly once when the platform approves', function (): void {
    Sanctum::actingAs($this->student);

    $this->postJson('/api/v1/billing/purchases', [
        'course' => $this->course->uuid,
        'package' => $this->package->uuid,
    ])->assertCreated();

    $order = Order::query()->withoutWorkspaceScope()->firstOrFail();

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $role = Role::findOrCreate('platform-billing', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::BILLING_PURCHASE_APPROVE, 'web'));

    $approver = $this->addWorkspaceMember($this->workspace, Roles::TENANT_OWNER);
    $approver->assignRole($role);
    // No 2FA enrolment needed: `2fa.required` passes until the account's
    // `two_factor_required_at` grace period is up, and this account has none.
    $this->setCurrentWorkspace($this->workspace, $approver);

    Sanctum::actingAs($approver);

    $this->postJson("/api/v1/orders/{$order->uuid}/approve")->assertOk();

    expect(app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): int => billingBalance($this->workspace, $this->student, $this->course)->refresh()->remaining_credits,
    ))->toBe(4);
});

it('leaves AdjustCredits refusing a movement with no reason', function (): void {
    // The bonus door itself: a grant with no recorded reason is the shape an
    // unaudited mint would take, so it is refused at the Action rather than
    // relying on whichever caller remembers to pass one.
    $balance = billingBalance($this->workspace, $this->student, $this->course);

    expect(fn () => app(AdjustCredits::class)->handle(
        $balance,
        CreditTransactionType::Bonus,
        5,
        '',
        'no-reason-key',
        $this->owner,
    ))->toThrow(DomainException::class);
});
