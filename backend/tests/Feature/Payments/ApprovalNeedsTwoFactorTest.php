<?php

declare(strict_types=1);

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Payments\Actions\CreateOrder;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
| ⚠️ THE MANDATE HAD TWO CALLERS AND BOTH WERE WORKSPACE PATHS, SO THE PEOPLE
| WHO HOLD THE MONEY AUTHORITY WERE THE ONES IT NEVER REACHED.
|
| `TwoFactorMandate::applyTo()` is called from `AcceptInvitation` and
| `CreateWorkspace` and nowhere else. Platform standing — `users.is_super_admin`
| or a `platform_staff` row — is the widest privilege the product grants and was
| the one grant that started no clock. Measured on production 2026-09-03: the
| platform's only super admin had NO `user_security_settings` row, so
| `RequireTwoFactor` passed them unconditionally and the `2fa.required` on
| `/orders/{uuid}/approve` was guarding nobody at all.
|
| ⚠️ AND `/admin` NEVER PASSED THROUGH THAT MIDDLEWARE AT ALL. The panel is
| session-authenticated, so moving the approval there would have moved it out
| from behind the second factor. Both surfaces ask `TwoFactorMandate` now, and
| both are measured here — one of them passing is not evidence about the other.
|
| ⚠️ THREE STATES, NOT TWO. «Refused» is a PAST deadline on an unenrolled
| account; a deadline still in the future passes, and so does no deadline at all.
| A test of the refusal alone is green against a build that refuses everybody,
| which is the same outage the whole approval move exists to avoid.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$workspace, $owner] = $this->createWorkspaceWithOwner();

    $course = Course::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'price_minor' => 4999,
        'is_sequential' => false,
    ]);

    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    $this->order = app(CreateOrder::class)->handle($course, $student);
    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $this->owner = $owner;
});

/** Put the officer past their deadline without enrolling them. */
function overdueOfficer(User $officer): User
{
    $officer->securitySettings()->updateOrCreate([], [
        'two_factor_required_at' => CarbonImmutable::now()->subDay(),
    ]);

    return $officer->refresh();
}

it('refuses an overdue officer at the API', function (): void {
    overdueOfficer($this->officer);
    Sanctum::actingAs($this->officer);

    $this->postJson("/api/v1/orders/{$this->order->uuid}/approve")
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required');

    // The ROW, not the response: a middleware that refuses after the Action ran
    // is a middleware that refused nothing.
    expect(Order::query()->withoutGlobalScopes()->sole()->status)->not->toBe('approved');
});

it('lets the same officer through while the deadline is still ahead', function (): void {
    // ⚠️ THE ALLOW DIRECTION, and it is what makes the refusal above worth
    // anything. The grace period is the whole design — a mandate with no window
    // is a lockout, and the production backfill hands every existing holder a
    // full one precisely so this deploy refuses nobody on its first day.
    $this->officer->securitySettings()->updateOrCreate([], [
        'two_factor_required_at' => CarbonImmutable::now()->addDays(14),
    ]);

    Sanctum::actingAs($this->officer->refresh());

    $this->postJson("/api/v1/orders/{$this->order->uuid}/approve")
        ->assertOk()
        ->assertJsonPath('status', 'approved');
});

it('refuses the same officer on the admin panel, which the middleware never sees', function (): void {
    /*
     * ⚠️ THE HOLE THE MOVE OPENED, MEASURED THROUGH THE REAL BUTTON. `/admin` is
     * session-authenticated and carries no `2fa.required`, so the panel's approve
     * action reached `ApproveOrder` around the middleware entirely. It asks
     * `TwoFactorMandate` itself now — the same class the middleware asks, so the
     * two surfaces cannot drift into two answers.
     *
     * ⚠️ AND THE ASSERTION IS THE ROW. Filament reports a successful action
     * whether or not the closure did anything; «the notification was shown» is
     * what a person sees, and «the order is still pending» is what protects them.
     */
    overdueOfficer($this->officer);
    $this->actingAs($this->officer);

    Livewire::test(ListOrders::class)
        ->callTableAction('approve', $this->order->getKey());

    expect(Order::query()->withoutGlobalScopes()->sole()->status)->not->toBe('approved');
});

it('lets the panel through once the officer is inside their window', function (): void {
    // The allow direction on the PANEL as well as on the API — without it the
    // test above is equally true of a button that never works.
    $this->officer->securitySettings()->updateOrCreate([], [
        'two_factor_required_at' => CarbonImmutable::now()->addDays(14),
    ]);

    $this->actingAs($this->officer->refresh());

    Livewire::test(ListOrders::class)
        ->callTableAction('approve', $this->order->getKey());

    expect(Order::query()->withoutGlobalScopes()->sole()->status)->toBe('approved');
});

it('starts the clock when platform standing is granted', function (): void {
    // ⚠️ A FRESH OFFICER, because the fixture above already has one — and the
    // question here is whether the GRANT wrote a deadline, not whether one
    // exists somewhere.
    $fresh = User::factory()->create();

    expect($fresh->securitySettings?->two_factor_required_at)->toBeNull();

    TwoFactorMandate::applyTo($fresh);

    expect($fresh->refresh()->securitySettings?->two_factor_required_at)->not->toBeNull()
        // In the FUTURE: stamping the past would enforce retroactively on
        // somebody who was never told a date.
        ->and($fresh->securitySettings?->two_factor_required_at?->isFuture())->toBeTrue();
});
