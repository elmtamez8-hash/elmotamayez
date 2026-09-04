<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/**
 * ⛔ INVERTED ON PURPOSE — spec 025 · FR-026 repeals 001 · FR-011.
 *
 * This file was 001's regression net for «the existing signup path must keep
 * working as the academy-creation path». That path was: register with no
 * platform role, then ask for a workspace in a later request — and that later
 * request is `POST /workspaces`, which FR-007 closes.
 *
 * So academy self-signup ENDS with this spec. It is not a side effect anybody
 * discovered afterwards; it is what «the platform has teachers under it, and no
 * academy layer above them» means, written down in FR-026 and recorded in the
 * plan's Complexity Tracking. The one door that remains is a platform
 * administrator creating a workspace from `/admin` (FR-009).
 *
 * The file is kept rather than deleted, and inverted rather than weakened: a
 * deleted regression net leaves nobody able to tell, two specs from now, whether
 * the closure was a decision or an accident.
 */
it('still registers an account with no platform role', function (): void {
    // Unchanged and deliberately so: registration itself is untouched. What used
    // to follow it is what closed.
    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'owner@academy.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertCreated();

    $user = User::where('email', 'owner@academy.test')->sole();

    expect($user->platform_role)->toBeNull()
        ->and($user->workspaces()->count())->toBe(0);
});

it('refuses to create a workspace for the registrant, and writes no row', function (): void {
    $owner = User::factory()->create();

    Sanctum::actingAs($owner);

    $before = Workspace::query()->count();

    /*
    | ⚠️ `403`, NOT `422`. The account holds no `workspaces.create` — the
    | permission lives in no tenant role at all — so `WorkspacePolicy::create()`
    | refuses before the request is even validated.
    |
    | ⚠️ AND THE ROW COUNT IS THE REAL ASSERTION. A refusal that returns 403 and
    | has already written a workspace is worse than an acceptance, because
    | nothing downstream would ever look for it.
    */
    $this->postJson('/api/v1/workspaces', [
        'name' => 'أكاديمية النور',
        'type' => 'academy',
    ])->assertForbidden();

    expect(Workspace::query()->count())->toBe($before)
        ->and(Workspace::query()->where('name', 'أكاديمية النور')->exists())->toBeFalse()
        ->and($owner->fresh()?->workspaces()->count())->toBe(0);
});
