<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Filament\Resources\RegionResource;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Payments\Filament\Resources\CouponResource;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Tenancy\Filament\Resources\FeatureFlagResource;
use App\Modules\Tenancy\Models\FeatureFlag;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Traits\BelongsToWorkspace;

/*
| Constitution v1.2.0 §I, kind (ب) — PLATFORM REFERENCE DATA: read by everyone
| who needs it, written only with a platform permission.
|
| ⚠️ THE ASSERTION THE CONSTITUTION ASKS FOR IS THE HIGHEST TENANT ROLE BEING
| REFUSED, and the kind-(أ) tests next door do not serve it: those prove that one
| person has one row across every teacher, which is a question about ownership,
| not about who may WRITE the platform's own vocabulary. A workspace owner holds
| every tenant permission there is, so if the wall holds against them it holds
| against everybody inside a workspace.
|
| ⚠️ AND IT IS MEASURED AT THE PANEL, because for all three of these the panel IS
| the write surface. A Filament LIST never calls the row policy — the only gate a
| table has is `canViewAny()` plus its own query — which is exactly how
| `OrderResource` once handed an assistant every student's email beside the
| amount they paid, with a correct `view()` sitting one method away.
*/

beforeEach(function (): void {
    // `Filament\Http\Middleware\Authenticate` aborts 403 for any user that does
    // not implement `FilamentUser` unless the environment is local, and this
    // product's panel rule lives in `EnsureFilamentAccess`, which runs after it.
    config(['app.env' => 'local']);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
});

it('refuses the workspace owner every platform-reference screen', function (): void {
    $this->actingAs($this->owner);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    expect(RegionResource::canViewAny())->toBeFalse('regions')
        ->and(CouponResource::canViewAny())->toBeFalse('coupons')
        ->and(FeatureFlagResource::canViewAny())->toBeFalse('feature flags');
});

it('refuses the workspace owner the permissions behind them', function (): void {
    $this->actingAs($this->owner);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // The classification itself: `platformPermissions()` is `all()` minus
    // everything any tenant role holds, so a permission that crept into a matrix
    // would show up here rather than three screens later.
    expect($this->owner->can(Permissions::TAXONOMY_MANAGE))->toBeFalse()
        ->and($this->owner->can(Permissions::FLAGS_MANAGE))->toBeFalse()
        ->and($this->owner->can(Permissions::BILLING_COUPONS_MANAGE))->toBeFalse();
});

it('lets the super admin, who holds every platform permission, in', function (): void {
    $platform = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($platform);

    // The opposite direction, and it is not decoration: a resource closed to
    // everybody passes the refusal above while being just as broken.
    expect(RegionResource::canViewAny())->toBeTrue('regions')
        ->and(FeatureFlagResource::canViewAny())->toBeTrue('feature flags');
});

it('refuses the owner a region delete even where a policy would be bypassed', function (): void {
    $region = Region::query()->first();

    $this->actingAs($this->owner);

    /*
    | ⚠️ REPEATED ON THE RESOURCE, NOT LEFT TO THE POLICY. `Gate::before` waves a
    | super admin past every policy method, so a refusal written only in
    | `TaxonomyPolicy::delete()` is no refusal at all for the person most likely
    | to press the button — and `student_profiles.region_id` names this row with
    | no foreign key behind it.
    */
    expect(RegionResource::canDelete($region))->toBeFalse();

    $this->actingAs(User::factory()->create(['is_super_admin' => true]));

    expect(RegionResource::canDelete($region))->toBeFalse();
});

it('keeps all three tables free of a workspace scope', function (): void {
    /*
    | The mirror-image defect. A `workspace_id` on `regions` would give the
    | platform one «الدوحة» per teacher and make the regional report a count of
    | teacher-region pairs; on `feature_flags` it would hide the `0` row every
    | reader falls back to; on `coupons` it would silently split one campaign.
    */
    foreach ([Region::class, FeatureFlag::class, Coupon::class] as $model) {
        expect(in_array(BelongsToWorkspace::class, class_uses_recursive($model), true))
            ->toBeFalse("{$model} must not be workspace-scoped");
    }
});
