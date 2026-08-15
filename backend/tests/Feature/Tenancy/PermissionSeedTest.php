<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;

/*
| Two invariants about the permission list itself, both of which fail silently.
|
| The first: a constant that never reaches `Permissions::all()` is never seeded,
| so nothing holds it and every `can()` against it returns false — for the super
| admin too, because `Gate::before` only answers for names it recognises. The
| symptom is a screen that 403s for everyone with nothing in any log, and the
| cause is one missing line in an array two hundred lines away. It has happened
| here before: `billing.collection.view` carries a comment saying so.
|
| The second: `RolePermissionMatrix::platformPermissions()` is derived by
| SUBTRACTION — everything in `all()` that no tenant role holds. That makes the
| ABSENCE of a name from every role array the entire mechanism protecting it, and
| an absence is the one thing a reader never notices. Adding
| `analytics.cross_teacher.view` to `$teacher` would look like a helpful line and
| would hand one teacher the error rates of every other teacher on the platform.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('seeds every constant, so none of them fails its own check', function (): void {
    $seeded = Permission::query()->pluck('name')->all();

    expect(array_diff(Permissions::all(), $seeded))->toBe([]);
});

it('keeps the platform permissions out of every tenant role', function (): void {
    $platform = RolePermissionMatrix::platformPermissions();

    // Not merely "some are platform-level" — the specific one spec 008 added has
    // to be among them, or the subtraction quietly stopped protecting it.
    expect($platform)->toContain(Permissions::ANALYTICS_CROSS_TEACHER_VIEW);

    $tenant = RolePermissionMatrix::tenantPermissions();

    expect(array_intersect($platform, $tenant))->toBe([]);
});

it('gives the assistant the bank to read and not to rewrite', function (): void {
    $assistant = RolePermissionMatrix::map()[Roles::ASSISTANT_TEACHER];

    // The widening in spec 008 is why this is asserted rather than assumed:
    // `questions.manage` used to govern one exam's questions and now governs a
    // bank shared across every exam, including permanent-delete refusal.
    expect($assistant)->toContain(Permissions::BANK_VIEW)
        ->and($assistant)->not->toContain(Permissions::QUESTIONS_MANAGE);
});

it('does not grant grading to an assistant by default, because FR-031 says "if granted"', function (): void {
    $map = RolePermissionMatrix::map();

    expect($map[Roles::ASSISTANT_TEACHER])
        ->not->toContain(Permissions::GRADING_PERFORM)
        ->and($map[Roles::TEACHER])
        ->toContain(Permissions::GRADING_PERFORM);
});
