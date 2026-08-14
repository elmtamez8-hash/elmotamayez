<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Filament\Resources\PlatformStaffResource;
use App\Modules\Tenancy\Models\PlatformStaff;
use App\Modules\Tenancy\Support\PermissionLabels;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;

/*
| The two screens, and who each of them opens for.
|
| ⚠️ THEY ANSWER DIFFERENT QUESTIONS AND THEREFORE HAVE DIFFERENT DOORS. The role
| screen rearranges authority INSIDE a workspace, so the owner holds it. The
| standing screen hands out the platform's own authority, so only the super admin
| does — a finance officer who could appoint a finance officer is one who can
| grant themselves a colleague.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();

    $this->platform = User::factory()->create(['is_super_admin' => true]);
});

it('opens the role screen for the owner and refuses the teacher', function (): void {
    $teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);

    $this->actingAs($this->owner);
    app(WorkspaceContext::class)->set($this->workspace);

    expect(RoleResource::canViewAny())->toBeTrue();

    $this->actingAs($teacher);

    expect(RoleResource::canViewAny())->toBeFalse();
});

it('opens the standing screen for the platform alone', function (): void {
    $this->actingAs($this->owner);
    app(WorkspaceContext::class)->set($this->workspace);

    expect(PlatformStaffResource::canViewAny())->toBeFalse();

    $this->actingAs($this->platform);

    expect(PlatformStaffResource::canViewAny())->toBeTrue()
        ->and(PlatformStaffResource::canCreate())->toBeTrue();
});

it('actually renders, which no boolean above can tell you', function (): void {
    /*
     * ⚠️ EVERY OTHER TEST HERE ASKS A STATIC METHOD A QUESTION. A resource that
     * throws the moment Filament builds its form or its table passes all of
     * them — the screen is the deliverable, and `canViewAny()` returning true is
     * not a screen.
     *
     * ⚠️ AND THE ENVIRONMENT IS FORCED TO `local`, WHICH IS NOT A TRICK TO MAKE
     * A TEST PASS. `Filament\Http\Middleware\Authenticate` aborts 403 for any
     * user that does not implement `FilamentUser` UNLESS the environment is
     * local — and this product's panel rule lives in `EnsureFilamentAccess`
     * instead, which runs after it. So in `testing` the panel refuses everybody,
     * including the super admin, and no test in this repository has ever
     * rendered a panel page. Local and production are unaffected; the assertions
     * below are about what the page does once that door is open.
     */
    config(['app.env' => 'local']);

    // ⚠️ `setCurrentWorkspace`, not `WorkspaceContext::set` alone. An HTTP
    // request re-resolves the workspace through `EnsureCurrentWorkspace`, which
    // falls back to `users.last_workspace_id` — and a factory-made owner has
    // none, so the team id lands null and every team-scoped role check answers
    // false.
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->actingAs($this->owner);

    $this->get('/admin/shield/roles')->assertOk();

    // ⚠️ AND THE PLATFORM ADMIN ON THE ROLE SCREEN TOO. The first version of
    // this test only opened it as the owner, so a super admin — whose authority
    // is a column and not a role, and who `RolePolicy` therefore refused — was
    // bounced to the login page by a screen every other assertion called open.
    $this->actingAs($this->platform);

    $this->get('/admin/shield/roles')->assertOk();
    $this->get('/admin/platform-staff')->assertOk();
    $this->get('/admin/platform-staff/create')->assertOk();
});

it('never offers a platform permission on the role screen', function (): void {
    $offered = array_keys(config('filament-shield.custom_permissions', []));

    // The picker's whole vocabulary, and the six that decide how much the
    // platform may be owed are not in it. The model refuses the write in any
    // case; this is the half that keeps a box from appearing that always errors.
    expect($offered)->toEqualCanonicalizing(RolePermissionMatrix::tenantPermissions())
        ->and($offered)->not->toContain('billing.pricing.manage', 'billing.collection.view');
});

it('names every offered permission in Arabic, since the panel has no other language', function (): void {
    /** @var array<string, string> $labels */
    $labels = config('filament-shield.custom_permissions', []);

    $untranslated = [];

    foreach ($labels as $permission => $label) {
        // The fallback is the permission name itself — readable, and a marker
        // that the composer did not recognise both halves. A screen full of
        // `sessions.host` is what this test exists to prevent.
        if ($label === $permission) {
            $untranslated[] = $permission;
        }
    }

    expect($untranslated)->toBe([]);
});

it('keeps the generators off, which is the whole shape of this integration', function (): void {
    // Shield's usual job is to INVENT permission names from Filament resources.
    // With either of these true it would write names no policy has heard of, and
    // the seventy-two constants would quietly stop being the whole vocabulary.
    expect(config('filament-shield.permissions.generate'))->toBeFalse()
        ->and(config('filament-shield.policies.generate'))->toBeFalse()
        // And the formatter, which would pascal-case `billing.audit.view` into a
        // string that matches nothing at all.
        ->and(config('filament-shield.permissions.format_custom_permission_keys'))->toBeFalse();
});

it('records who granted a standing, and refuses to let it be edited afterwards', function (): void {
    $officer = User::factory()->create();

    $standing = PlatformStaff::query()->create([
        'user_id' => $officer->getKey(),
        'role' => Roles::FINANCE_ADMIN,
        'assigned_by' => $this->platform->getKey(),
        'reason' => 'يعتمد إيصالات التحويل البنكي',
    ]);

    $this->actingAs($this->platform);

    // Granted and revoked, never edited: rewriting who appointed whom and why is
    // rewriting the record itself.
    expect(PlatformStaffResource::canEdit($standing))->toBeFalse()
        ->and($standing->assigned_by)->toBe($this->platform->getKey())
        ->and($standing->reason)->not->toBe('');
});

it('composes a label for a permission nobody has written yet', function (): void {
    // The reason the labels are composed and not listed: the next permission
    // gets Arabic without anybody remembering this file exists.
    expect(PermissionLabels::for('courses.publish'))->toBe('نشر — الكورسات')
        ->and(PermissionLabels::for('sessions.host'))->toBe('استضافة — الحصص')
        // And an unknown subject falls back to the raw name rather than a blank,
        // which would be a tick box for something nobody can identify.
        ->and(PermissionLabels::for('widgets.frobnicate'))->toBe('widgets.frobnicate');
});
