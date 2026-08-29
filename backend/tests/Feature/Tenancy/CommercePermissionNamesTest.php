<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\PermissionLabels;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;

/**
 * Spec 011 — the five new permissions, pinned on the side they belong to.
 *
 * ⚠️ WHY A LITERAL PIN AND NOT THE EXISTING GUARD. `PermissionPanelTest` compares
 * `PermissionLabels::tenantMap()` against `RolePermissionMatrix::tenantPermissions()`
 * — and `tenantMap()` is BUILT by looping `tenantPermissions()`. It is a
 * derivation compared with itself, so it agrees no matter which side a name is
 * on. Put `billing.coupons.manage` in the teacher's array by mistake and that
 * test stays green while every teacher on the platform can mint a discount spent
 * out of the platform's own commission.
 *
 * ⚠️ AND THE CLASSIFICATION IS MADE BY ABSENCE, WHICH IS WHY IT IS EASY TO GET
 * WRONG IN SILENCE. `platformPermissions()` is `Permissions::all()` minus
 * everything any tenant role holds — so a platform permission is declared by
 * NOT being written anywhere, and a constant left out of `all()` is held by
 * nobody at all, super admin included. Both mistakes look like a missing line.
 *
 * Spec 009's `taxonomy.manage` is the precedent: declared, seeded, asserted
 * platform-level, and read by no file in the tree — a classification that was
 * perfectly true of a constant nobody called.
 */
it('registers all five commerce permissions in the platform-wide list', function (): void {
    // A constant outside `all()` is never seeded, so every check against it
    // fails — including Gate::before's, which is why "super admin can do
    // anything" would not save it.
    expect(Permissions::all())->toContain(
        Permissions::STORE_ITEMS_MANAGE,
        Permissions::STORE_SHIPMENTS_MANAGE,
        Permissions::PLANS_MANAGE,
        Permissions::BILLING_COUPONS_MANAGE,
        Permissions::FLAGS_MANAGE,
    );
});

it('puts the three workspace-owned commerce permissions on the teacher', function (): void {
    $tenant = RolePermissionMatrix::tenantPermissions();

    // The teacher's own goods and their own plan calendar. Without these the
    // routes 403 for the only person they were built for.
    expect($tenant)->toContain(
        Permissions::STORE_ITEMS_MANAGE,
        Permissions::STORE_SHIPMENTS_MANAGE,
        Permissions::PLANS_MANAGE,
    );
});

it('keeps coupons and feature flags off every tenant role', function (): void {
    $tenant = RolePermissionMatrix::tenantPermissions();
    $platform = RolePermissionMatrix::platformPermissions();

    // ⚠️ BOTH DIRECTIONS. Asserting only the absence would pass just as well
    // against a constant that was never added to `all()` — present in neither
    // list, held by nobody, and looking exactly like a correct platform
    // permission from the tenant side.
    expect($tenant)->not->toContain(Permissions::BILLING_COUPONS_MANAGE)
        ->and($tenant)->not->toContain(Permissions::FLAGS_MANAGE)
        ->and($platform)->toContain(Permissions::BILLING_COUPONS_MANAGE)
        ->and($platform)->toContain(Permissions::FLAGS_MANAGE);
});

/**
 * ⚠️ `analytics.view` IS NOT THE PLATFORM DASHBOARD'S PERMISSION, and spec 011's
 * contracts said it was until the review measured it.
 *
 * It sits in `$assistantTeacher`, so every role above inherits it — meaning a
 * dashboard gated on it would show every assistant on the platform the student
 * and teacher counts, the overdue receivables, the collection rate and the churn
 * of every workspace. FR-046 inverted, by a permission almost everybody holds.
 *
 * The correct constant already exists and is platform-level by the same absence
 * this file pins.
 */
it('leaves the cross-teacher analytics permission platform-level and analytics.view tenant-level', function (): void {
    expect(RolePermissionMatrix::tenantPermissions())->toContain(Permissions::ANALYTICS_VIEW)
        ->and(RolePermissionMatrix::platformPermissions())->toContain(Permissions::ANALYTICS_CROSS_TEACHER_VIEW)
        ->and(RolePermissionMatrix::tenantPermissions())->not->toContain(Permissions::ANALYTICS_CROSS_TEACHER_VIEW);
});

it('renders every commerce permission in Arabic', function (): void {
    // An Arabic-only panel showing `store.items.manage` on a tick box is the
    // defect PermissionLabels exists to prevent, and a three-part name is
    // exactly what its composer cannot reach.
    foreach ([
        Permissions::STORE_ITEMS_MANAGE,
        Permissions::STORE_SHIPMENTS_MANAGE,
        Permissions::PLANS_MANAGE,
        Permissions::BILLING_COUPONS_MANAGE,
        Permissions::FLAGS_MANAGE,
    ] as $permission) {
        $label = PermissionLabels::for($permission);

        expect($label)->not->toBe($permission)
            ->and($label)->not->toBe('')
            ->and(preg_match('/[A-Za-z]/', $label))->toBe(0);
    }
});
