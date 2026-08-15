<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| What the panel is allowed to OFFER.
|
| ⚠️ NONE OF THIS IS THE AUTHORISATION BOUNDARY — every name here is enforced by
| a policy, and the tests for that live beside those policies. What this file
| guards is the list the client reads to decide which links to draw, after a
| student signed in and was shown the course editor, the exam builder, the
| question bank and the settlement statement.
*/

it('tells a student what they may do and nothing more', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);

    Sanctum::actingAs($student);

    $granted = $this->getJson('/api/v1/auth/me')->json('permissions');

    expect($granted)->toContain(Permissions::ATTEMPTS_SUBMIT)
        ->and($granted)->toContain(Permissions::SESSIONS_VIEW)
        // The five the sidebar used to offer them.
        ->and($granted)->not->toContain(Permissions::COURSES_UPDATE)
        ->and($granted)->not->toContain(Permissions::EXAMS_UPDATE)
        ->and($granted)->not->toContain(Permissions::BANK_VIEW)
        ->and($granted)->not->toContain(Permissions::ANALYTICS_VIEW)
        ->and($granted)->not->toContain(Permissions::SETTLEMENT_STATEMENT_VIEW);
});

it('answers for the workspace the reader is in, not for the account', function (): void {
    [$first, $firstOwner] = $this->createWorkspaceWithOwner();
    [$second, $secondOwner] = $this->createWorkspaceWithOwner();

    /*
    | ⚠️ ONE PERSON, TWO WORKSPACES, TWO ROLES — and a single-workspace fixture
    | would pass whatever the answer was. spatie runs in team mode, so a
    | permission is never true of an ACCOUNT; it is true of an account inside one
    | workspace. A teacher at one academy and a student at another must see two
    | different sidebars, and the same list computed once for the person is how
    | they would see the first academy's menu at the second.
    */
    $person = $this->addWorkspaceMember($first, Roles::TEACHER);
    $this->addWorkspaceMember($second, Roles::STUDENT, $person);

    Sanctum::actingAs($person);

    $this->setCurrentWorkspace($first, $person);
    expect($this->getJson('/api/v1/auth/me')->json('permissions'))->toContain(Permissions::BANK_VIEW);

    $this->setCurrentWorkspace($second, $person);
    expect($this->getJson('/api/v1/auth/me')->json('permissions'))->not->toContain(Permissions::BANK_VIEW);
});

it('gives the super admin a list rather than an empty sidebar', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();

    $admin = User::factory()->create(['is_super_admin' => true, 'last_workspace_id' => $workspace->getKey()]);

    Sanctum::actingAs($admin);

    $granted = $this->getJson('/api/v1/auth/me')->json('permissions');

    /*
    | ⚠️ THE ASSERTION THAT CATCHES `getAllPermissions()`. A super admin holds no
    | role and no permission row: their powers come from a `Gate::before` hook,
    | which spatie's own accessors cannot see. Built from spatie, this list comes
    | back EMPTY and the platform operator signs in to a sidebar with nothing on
    | it — while every other test in the suite stays green.
    */
    expect($granted)->toContain(Permissions::BILLING_AUDIT_VIEW)
        ->and($granted)->toContain(Permissions::ANALYTICS_CROSS_TEACHER_VIEW)
        ->and(count($granted))->toBe(count(Permissions::all()));
});

it('answers the login request with the same list as the profile request', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $teacher = $this->addWorkspaceMember($workspace, Roles::TEACHER);
    $teacher->forceFill(['password' => bcrypt('secret-password')])->save();

    // ⚠️ THE LOGIN RESPONSE IS THE ONE THAT BREAKS. During `/auth/login` there is
    // no authenticated user yet, so the workspace context resolves as a GUEST and
    // freezes there — the singleton caches its resolution. A list computed
    // without wrapping the read in `forWorkspace()` comes back empty, and the
    // panel opens with an empty menu until the first reload.
    $this->asGuest();

    $granted = $this->postJson('/api/v1/auth/login', [
        'email' => $teacher->email,
        'password' => 'secret-password',
    ])->json('user.permissions');

    expect($granted)->toContain(Permissions::BANK_VIEW);
});
