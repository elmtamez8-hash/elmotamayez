<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Filament\Resources\CouponResource\Pages\CreateCoupon;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Tenancy\Filament\Resources\PlatformStaffResource\Pages\CreatePlatformStaff;
use App\Modules\Tenancy\Filament\Resources\PlatformStaffResource\Pages\ListPlatformStaff;
use App\Modules\Tenancy\Models\PlatformStaff;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
| ⛔ THREE PLATFORM MONEY WRITES THE SECOND FACTOR NEVER REACHED.
|
| `PUT /admin/billing/pricing` moves the price of every future sale and carried
| no `2fa.required`. The coupon screen (money out of the platform's commission)
| and the platform-staff screen (who holds the money authority at all) live in
| `/admin`, which is session-authenticated and never passes through that
| middleware — `OrderResource` asks `TwoFactorMandate` itself for exactly that
| reason, and these two did not.
|
| ⚠️ BOTH DIRECTIONS FOR EVERY DOOR, AND THE ASSERTION IS THE ROW. A refusal
| alone is green against a build that refuses everybody; Filament reports a
| finished action whether or not anything was written.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create(['is_super_admin' => true]);
});

/** Past the deadline and not enrolled (`$overdue`), or still inside the window. */
function platformWriterDeadline(User $user, bool $overdue): User
{
    $user->securitySettings()->updateOrCreate([], [
        'two_factor_required_at' => $overdue ? CarbonImmutable::now()->subDay() : CarbonImmutable::now()->addDays(14),
    ]);

    return $user->refresh();
}

it('refuses an overdue admin the platform pricing write, and lets them through in their window', function (bool $overdue): void {
    Sanctum::actingAs(platformWriterDeadline($this->admin, $overdue));

    $response = $this->putJson('/api/v1/admin/billing/pricing', ['gateway_fee_bps' => 321]);

    if ($overdue) {
        $response->assertForbidden()->assertJsonPath('code', 'two_factor_required');
        $this->getJson('/api/v1/admin/billing/pricing')->assertOk()
            ->assertJsonMissing(['gateway_fee_bps' => 321]);
    } else {
        $response->assertOk()->assertJsonPath('gateway_fee_bps', 321);
    }
})->with(['overdue' => true, 'inside the window' => false]);

it('refuses an overdue admin a new coupon on the panel', function (bool $overdue): void {
    $this->actingAs(platformWriterDeadline($this->admin, $overdue));

    Livewire::test(CreateCoupon::class)
        ->fillForm([
            'code' => 'SAVE10',
            'value_kind' => 'percent',
            'value' => 10,
            'is_active' => true,
        ])
        ->call('create');

    expect(Coupon::query()->where('code', 'SAVE10')->exists())->toBe(! $overdue);
})->with(['overdue' => true, 'inside the window' => false]);

it('refuses an overdue admin granting platform standing on the panel', function (bool $overdue): void {
    $this->actingAs(platformWriterDeadline($this->admin, $overdue));
    $grantee = User::factory()->create();

    Livewire::test(CreatePlatformStaff::class)
        ->fillForm([
            'user_id' => $grantee->getKey(),
            'role' => Roles::FINANCE_ADMIN,
            'reason' => 'finance officer for Q4',
        ])
        ->call('create');

    expect(PlatformStaff::query()->where('user_id', $grantee->getKey())->exists())->toBe(! $overdue);
})->with(['overdue' => true, 'inside the window' => false]);

it('refuses an overdue admin revoking platform standing on the panel', function (bool $overdue): void {
    $officer = makePlatformStaff(Roles::FINANCE_ADMIN);
    $standing = PlatformStaff::query()->where('user_id', $officer->getKey())->sole();

    $this->actingAs(platformWriterDeadline($this->admin, $overdue));

    Livewire::test(ListPlatformStaff::class)
        ->callTableAction('delete', $standing->getKey());

    expect(PlatformStaff::query()->whereKey($standing->getKey())->exists())->toBe($overdue);
})->with(['overdue' => true, 'inside the window' => false]);
