<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Who decides how a workspace collects, and who turns a receipt into credits.
|
| ⚠️ THE MODE IS THE PLATFORM'S DECISION, NOT THE TEACHER'S, AND THAT IS A
| CORRECTION. `BILLING_SETTINGS_MANAGE` sat on the workspace owner, argued as "an
| ownership decision about the workspace, not a teaching one". The argument
| ignores where the money actually goes: since spec 014 a teacher is paid from
| DELIVERY, not from collection, so the debt a teacher would be allowing on
| themselves is a debt on the PLATFORM. Deferred payment is the platform lending
| money; the borrower's teacher does not get to set the terms.
|
| ⚠️ AND APPROVING A RECEIPT IS DELEGABLE WITHOUT BEING TENANT-SIDE. Requiring a
| super-admin for every credit purchase on the platform is a bottleneck with a
| person in it; handing it to the teacher makes the payee the minter. The finance
| role is the third answer: a GLOBAL spatie role (team_id null, like super-admin),
| holding the approval and nothing else, assigned to nobody by default.
*/

beforeEach(function (): void {
    // The global roles are reference data — seeded by RolesAndPermissionsSeeder,
    // not by the WorkspaceCreated listener, which only makes the team-scoped
    // ones. Most suites never need them because Gate::before answers for a
    // super-admin off the `is_super_admin` flag; finance-admin has no flag and
    // is the role itself.
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

it('refuses the workspace owner the billing mode', function (): void {
    Sanctum::actingAs($this->owner);

    $this->patchJson('/api/v1/manage/billing/settings', [
        'mode' => BillingMode::ManualCollection->value,
    ])->assertForbidden();

    expect(app(BillingSettings::class)->mode($this->workspace->refresh()))
        ->toBe(BillingMode::PrepaidCredits);
});

it('lets the platform switch it', function (): void {
    $platform = User::factory()->create(['is_super_admin' => true]);
    $this->workspace->members()->attach($platform->getKey(), ['role' => Roles::TENANT_OWNER, 'joined_at' => now()]);

    Sanctum::actingAs($platform);

    $this->patchJson('/api/v1/manage/billing/settings', [
        'mode' => BillingMode::ManualCollection->value,
    ])->assertOk();
});

/*
| ⚠️ BLOCKED ON A SCHEMA FACT, NOT ON A DECISION.
|
| `model_has_roles.team_id` is NOT NULL *and part of the composite primary key*
| in spatie's own stock migration, so a role with `team_id = null` can be SEEDED
| but can never be ASSIGNED to anybody. That is why `super-admin` exists as a
| role in this database and is held by no user: every super-admin capability
| runs off the `users.is_super_admin` column and `Gate::before`.
|
| So a delegated platform role cannot be a global spatie role here. The
| mechanism has to be chosen — a second column beside `is_super_admin`, a
| `platform_staff` table (which the constitution's "anything true of one role
| gets its own table" points at, and which doubles as the audit trail for who
| may approve money), or altering a primary key on the auth tables. That is the
| user's call, and these two skips are the marker for it.
|
| The role constant, the matrix row and the seeder are already in place and
| correct as reference data; only the assignment path is missing.
*/
it('gives the finance role the receipt approval and nothing beside it', function (): void {
    $finance = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $finance->assignRole(Roles::FINANCE_ADMIN);
    $finance->refresh();

    expect($finance->can(Permissions::BILLING_PURCHASE_APPROVE))->toBeTrue()
        // The three that stay with the platform alone. A role that could price a
        // package, move a ceiling or switch the mode is a second super-admin
        // wearing a narrower name.
        ->and($finance->can(Permissions::BILLING_PACKAGES_MANAGE))->toBeFalse()
        ->and($finance->can(Permissions::BILLING_CREDITS_ADJUST))->toBeFalse()
        ->and($finance->can(Permissions::BILLING_SETTINGS_MANAGE))->toBeFalse()
        ->and($finance->can(Permissions::BILLING_LIMIT_MANAGE))->toBeFalse();
})->skip('finance-admin cannot be assigned until the platform-staff mechanism is chosen');

it('approves a credit order as the finance role, in a workspace it does not belong to', function (): void {
    // The whole point of a GLOBAL role: receipts arrive from every workspace on
    // the platform, and a finance officer who had to be a member of each one
    // would be a member of all of them — which is a super-admin with extra steps.
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $order = Order::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $student->getKey(),
        'kind' => OrderKind::Credits,
        'amount' => '100.00',
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    $finance = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $finance->assignRole(Roles::FINANCE_ADMIN);

    expect($finance->refresh()->can('approve', $order))->toBeTrue();
})->skip('finance-admin cannot be assigned until the platform-staff mechanism is chosen');

it('still refuses a teacher the credit order their own students pay', function (): void {
    // Unchanged, and re-asserted here because the finance role is the change
    // most likely to be "simplified" into a tenant permission later.
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $order = Order::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $student->getKey(),
        'kind' => OrderKind::Credits,
        'amount' => '100.00',
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'pending',
    ]);

    expect($this->owner->can('approve', $order))->toBeFalse();
});
