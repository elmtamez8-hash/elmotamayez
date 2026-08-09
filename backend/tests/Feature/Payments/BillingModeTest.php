<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\UpdateBillingSettings;
use App\Modules\Payments\Data\BillingSettingsData;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\BillingCadence;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Exceptions\InsufficientCreditsException;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-005 · FR-011 · FR-012 · FR-014 — the mode is a setting, and switching it
| changes what happens NEXT and nothing else.
|
| Two axes, deliberately separate (see BillingCadence): the MODE says how money
| arrives and whether a debt is allowed; the CADENCE says how much is settled at
| once. Prepaid-per-session is the launch default; prepaid monthly is expressed
| by the package bought, and deferred monthly by the ceiling.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
    $this->balance = billingBalance($this->workspace, $this->student);
    $this->settings = app(BillingSettings::class);
    $this->ledger = app(CreditLedger::class);
});

function switchTo(BillingMode $mode, ?BillingCadence $cadence = null): void
{
    app(UpdateBillingSettings::class)->handle(
        test()->workspace,
        new BillingSettingsData(mode: $mode, cadence: $cadence),
    );

    test()->workspace->refresh();
    test()->balance->refresh()->setRelation('workspace', test()->workspace);
}

function tryConsume(int $credits = 1, int $source = 1): void
{
    $balance = test()->balance;

    app(CreditLedger::class)->post(new CreditMovement(
        balance: $balance,
        type: CreditTransactionType::Consume,
        credits: -$credits,
        sourceType: 'booking',
        sourceId: $source,
        enforceFloor: true,
        zeroFloor: app(CreditLedger::class)->floorForBalance($balance) === 0,
    ));
}

it('defaults a fresh workspace to prepaid, one session at a time', function (): void {
    expect($this->settings->mode($this->workspace))->toBe(BillingMode::PrepaidCredits)
        ->and($this->settings->cadence($this->workspace))->toBe(BillingCadence::Session)
        ->and($this->settings->cadenceAllowsCredits($this->workspace))->toBe(0);
});

it('refuses a booking at zero in prepaid, whatever the credit limit says', function (): void {
    // A ceiling left over from a deferring period. FR-014 says prepaid never goes
    // below zero, so the ceiling must not be reachable — otherwise a mode switch
    // silently keeps extending credit it was chosen to stop.
    $this->balance->forceFill(['credit_limit_credits' => 5])->save();

    expect($this->ledger->floorForBalance($this->balance->refresh()))->toBe(0)
        ->and(fn () => tryConsume())->toThrow(InsufficientCreditsException::class);
});

it('allows a booking down to the ceiling once the mode defers', function (): void {
    $this->balance->forceFill(['credit_limit_credits' => 2])->save();

    switchTo(BillingMode::ManualCollection, BillingCadence::Month);

    tryConsume(1, 1);
    tryConsume(1, 2);

    expect($this->balance->refresh()->remaining_credits)->toBe(-2)
        ->and(fn () => tryConsume(1, 3))->toThrow(InsufficientCreditsException::class);
});

it('gives a deferring workspace a starting ceiling from its cadence', function (): void {
    switchTo(BillingMode::ManualCollection, BillingCadence::Month);

    // Postpaid monthly means a month's worth of sessions may stand unpaid.
    expect($this->settings->cadenceAllowsCredits($this->workspace))
        ->toBe(min(BillingCadence::Month->sessionsPerCycle(), $this->settings->maxLimitCredits()));

    switchTo(BillingMode::ManualCollection, BillingCadence::Session);

    // Postpaid per session is a ceiling of exactly one.
    expect($this->settings->cadenceAllowsCredits($this->workspace))->toBe(1);
});

/*
| FR-012 — the switch reaches forward only.
|
| A student 3 down when the workspace moves to prepaid STAYS 3 down: the floor
| rises so they cannot go further, and the debt is collected the way it always
| was. Clearing it would forgive money owed; reversing it would invent a charge.
*/
it('recomputes no existing entry and voids no standing debt', function (): void {
    $this->balance->forceFill(['credit_limit_credits' => 4])->save();

    switchTo(BillingMode::ManualCollection, BillingCadence::Month);

    tryConsume(3, 1);

    $before = CreditTransaction::query()->withoutWorkspaceScope()->get()
        ->map(fn (CreditTransaction $entry): array => [
            $entry->uuid, $entry->type->value, $entry->credits,
        ])->all();

    expect($this->balance->refresh()->remaining_credits)->toBe(-3);

    switchTo(BillingMode::PrepaidCredits, BillingCadence::Session);

    $after = CreditTransaction::query()->withoutWorkspaceScope()->get()
        ->map(fn (CreditTransaction $entry): array => [
            $entry->uuid, $entry->type->value, $entry->credits,
        ])->all();

    expect($after)->toBe($before)
        ->and($this->balance->refresh()->remaining_credits)->toBe(-3)
        // And the floor has risen, so the debt cannot deepen.
        ->and($this->ledger->floorForBalance($this->balance))->toBe(0)
        ->and(fn () => tryConsume(1, 2))->toThrow(InsufficientCreditsException::class);
});

// FR-015 — a mode with no way to take money must not be saveable.
it('refuses payment_gateway before spec 007 ships it', function (): void {
    expect(fn () => switchTo(BillingMode::PaymentGateway))
        ->toThrow(DomainException::class)
        ->and($this->settings->mode($this->workspace->refresh()))->toBe(BillingMode::PrepaidCredits);
});

/*
| Q-11 · FR-010ب — prepaid monthly is a shape the product SELLS, not a
| contradiction, and what the lifted refusal used to protect is protected here
| instead.
|
| The refusal read "the cadence decides how much is bought ahead". It does not:
| the PACKAGE does (FR-016). A monthly cadence under a prepaid mode says which
| package leads the purchase screen and nothing else — so the credit count, the
| price and the ceiling must all be deaf to it. Two of those three are testable
| today; pricing arrives with US3.
*/
it('accepts a prepaid workspace on a monthly cadence, and derives nothing from it', function (): void {
    switchTo(BillingMode::PrepaidCredits, BillingCadence::Month);

    expect($this->settings->mode($this->workspace))->toBe(BillingMode::PrepaidCredits)
        ->and($this->settings->cadence($this->workspace))->toBe(BillingCadence::Month)
        // A month's worth of sessions, and not one credit of ceiling from it.
        ->and($this->settings->cadenceAllowsCredits($this->workspace))->toBe(0)
        ->and($this->ledger->floorForBalance($this->balance))->toBe(0)
        ->and(fn () => tryConsume())->toThrow(InsufficientCreditsException::class);
});

/*
| A mode-only PATCH leaves the cadence alone — the plain PATCH contract, and the
| reason the normalisation had to go with the refusal.
|
| While prepaid+monthly was refused, a mode-only request forced the cadence back
| to per_session so that leaving deferral did not fail over a field nobody
| touched. Post-Q-11 that same line resets a legal monthly cadence every time any
| other field is saved.
*/
it('switches mode alone without touching the stored cadence', function (): void {
    switchTo(BillingMode::ManualCollection, BillingCadence::Month);

    Sanctum::actingAs(billingAdmin());

    $this->patchJson('/api/v1/manage/billing/settings', [
        'mode' => BillingMode::PrepaidCredits->value,
    ])->assertOk()
        ->assertJsonPath('mode', BillingMode::PrepaidCredits->value)
        ->assertJsonPath('cadence', BillingCadence::Month->value);

    expect($this->settings->cadence($this->workspace->refresh()))->toBe(BillingCadence::Month);

    // And every option is offered, whichever mode is stored.
    $payload = $this->getJson('/api/v1/manage/billing/settings')->assertOk()->json();

    expect(collect($payload['modes'])->firstWhere('value', BillingMode::PrepaidCredits->value))
        ->unavailable_reason->toBeNull()
        ->and(collect($payload['cadences'])->pluck('unavailable_reason')->unique()->all())
        ->toBe([null]);
});

it('names a reason for every mode it refuses', function (): void {
    // A boolean here becomes "تعذّر الحفظ" on a screen where the person can see
    // nothing wrong with what they typed.
    expect($this->settings->refusalToAdopt(BillingMode::PaymentGateway))
        ->toBeString()->not->toBe('')
        ->and($this->settings->refusalToAdopt(BillingMode::PrepaidCredits))
        ->toBeNull();
});

// The route, and the permission behind it -----------------------------------

function billingAdmin(): User
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($test->workspace->getKey());

    $role = Role::findOrCreate('billing-admin', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::BILLING_SETTINGS_MANAGE, 'web'));

    $admin = $test->addWorkspaceMember($test->workspace, Roles::TENANT_OWNER);
    $admin->assignRole($role);
    $test->setCurrentWorkspace($test->workspace, $admin);

    return $admin;
}

it('switches the mode over the API with no deploy', function (): void {
    Sanctum::actingAs(billingAdmin());

    $this->patchJson('/api/v1/manage/billing/settings', [
        'mode' => BillingMode::ManualCollection->value,
        'cadence' => BillingCadence::Month->value,
        'alert_thresholds' => [4, 2],
    ])->assertOk()
        ->assertJsonPath('mode', BillingMode::ManualCollection->value)
        ->assertJsonPath('cadence', BillingCadence::Month->value)
        ->assertJsonPath('allows_deferral', true);

    expect($this->settings->alertThresholds($this->workspace->refresh()))->toBe([4, 2]);
});

it('changes one key and leaves the rest alone', function (): void {
    Sanctum::actingAs(billingAdmin());

    $this->patchJson('/api/v1/manage/billing/settings', [
        'alert_thresholds' => [6, 3, 1],
    ])->assertOk();

    $this->patchJson('/api/v1/manage/billing/settings', [
        'zero_balance_behavior' => 'remind',
    ])->assertOk();

    // A PATCH carrying one key must not reset what it did not mention — the
    // thresholds would be back at their defaults and nobody would notice until
    // an alert failed to fire.
    expect($this->settings->alertThresholds($this->workspace->refresh()))->toBe([6, 3, 1]);
});

it('answers 422 for an unsupported mode and 403 without the permission', function (): void {
    Sanctum::actingAs(billingAdmin());

    $this->patchJson('/api/v1/manage/billing/settings', ['mode' => 'barter'])
        ->assertStatus(422);

    $member = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->setCurrentWorkspace($this->workspace, $member);
    Sanctum::actingAs($member);

    $this->patchJson('/api/v1/manage/billing/settings', [
        'mode' => BillingMode::ManualCollection->value,
    ])->assertForbidden();
});
