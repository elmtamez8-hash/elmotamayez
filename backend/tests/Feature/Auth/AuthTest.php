<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\UserStatus;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * One invitation, built without a workspace context.
 *
 * `Invitation` uses `BelongsToWorkspace`, and the caller under test is a guest —
 * so the scope is inert on the way in and must be bypassed on the way out here.
 */
function inviteFor(string $email, string $role = Roles::TEACHER, ?CarbonImmutable $expiresAt = null): Invitation
{
    $workspace = Workspace::factory()->create();

    return Invitation::withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->getKey(),
        'email' => $email,
        'role' => $role,
        'token' => Str::random(40),
        'expires_at' => $expiresAt ?? CarbonImmutable::now()->addWeek(),
    ]);
}

describe('registration', function (): void {
    /*
    | ⚠️ THREE CASES HERE USED TO PROVE THE DEFECT THEY EXIST TO PREVENT.
    |
    | `POST /auth/register` carried NO middleware — no rate limit, no idempotency
    | key — and its controller was a bare `User::create()`. So «registers a new
    | user successfully» was a green assertion about an endpoint that minted an
    | account for anyone who asked: no `platform_role`, no `student_profiles`
    | row, and `users.status` taking its column default of `active` — which means
    | the guardian-consent gate spec 013 built for minors (FR-009) was not
    | bypassed by a flaw in it, it was simply never reached, because
    | `RegisterStudent` is the only writer that computes it.
    |
    | ⚠️ AND THE FIX IS THE MIDDLEWARE, NOT AN INVITATION REQUIREMENT. This door
    | serves two people — an academy founder with nothing in hand, and an invitee
    | with a token — so a required token closes the founder's only path. It was
    | required for one commit and `AcademySignupUnchangedTest` caught it. The
    | token is a CLAIM, checked when it is made; the rate limit is the guard.
    */

    it('registers the account an invitation was addressed to', function (): void {
        $invitation = inviteFor('jane@example.com');

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invitation' => $invitation->token,
        ])->assertCreated()->assertJsonPath('email', 'jane@example.com');

        $user = User::where('email', 'jane@example.com')->firstOrFail();

        // The two columns the bare `User::create()` never wrote. A null role is
        // the ambiguity; `active` by column default is the gate not running.
        expect($user->platform_role)->toBe(PlatformRole::Teacher)
            ->and($user->status)->toBe(UserStatus::Active->value);
    });

    /*
    | ⚠️ AND IT MUST STAY OPEN WITHOUT ONE. An earlier version of this fix made
    | the invitation required, which closed the academy founder's only door —
    | `AcademySignupUnchangedTest` caught it, and this case is the reminder here
    | so the next reader does not re-tighten it. Null is the correct platform
    | role: founding a workspace is a workspace role, granted a request later.
    */
    it('still registers an academy founder with no invitation and no platform role', function (): void {
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jane',
            'email' => 'founder@academy.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::where('email', 'founder@academy.test')->firstOrFail();

        expect($user->platform_role)->toBeNull()
            ->and($user->status)->toBe(UserStatus::Active->value);
    });

    it('refuses an invitation addressed to somebody else', function (): void {
        // A forwarded token. Without this check it creates an account that then
        // cannot accept the invitation — a live account nobody asked for.
        $invitation = inviteFor('invited@example.com');

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Mallory',
            'email' => 'someone-else@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invitation' => $invitation->token,
        ])->assertStatus(422);

        expect(User::where('email', 'someone-else@example.com')->exists())->toBeFalse();
    });

    it('refuses an unknown token', function (): void {
        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invitation' => Str::random(40),
        ])->assertStatus(422);

        expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
    });

    it('refuses an expired invitation', function (): void {
        $invitation = inviteFor('jane@example.com', Roles::TEACHER, CarbonImmutable::now()->subDay());

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invitation' => $invitation->token,
        ])->assertStatus(422);

        expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
    });

    it('refuses an invitation that was already used', function (): void {
        $invitation = inviteFor('jane@example.com');
        $invitation->forceFill(['accepted_at' => CarbonImmutable::now()])->save();

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invitation' => $invitation->token,
        ])->assertStatus(422);

        expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
    });

    /*
    | ⚠️ THE CASE THE WHOLE FIX EXISTS FOR. `invitations.role` accepts `student`,
    | and a student may be a minor — the one registration that needs a date of
    | birth and the guardian branch. This screen does not ask for one, so the
    | invitation is refused and the person is sent to `/signup/student`, which
    | already computes the gate. One implementation of it, not two.
    */
    it('refuses a student invitation and leaves the guardian gate to the signup flow', function (): void {
        $invitation = inviteFor('child@example.com', Roles::STUDENT);

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Child',
            'email' => 'child@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invitation' => $invitation->token,
        ])->assertStatus(422);

        expect(User::where('email', 'child@example.com')->exists())->toBeFalse();
    });

    it('validates required fields', function (): void {
        $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'email', 'password']);
    });

    it('prevents duplicate email registration', function (): void {
        User::factory()->create(['email' => 'taken@example.com']);
        $invitation = inviteFor('taken@example.com');

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jane',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invitation' => $invitation->token,
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    });
});

describe('login', function (): void {
    it('authenticates with valid credentials and returns a token', function (): void {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user' => ['uuid', 'email'], 'token']);

        expect($response->json('token'))->not()->toBeNull();
    });

    it('rejects invalid credentials', function (): void {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    });
});

describe('authenticated endpoints', function (): void {
    it('returns the current user via /me', function (): void {
        $user = Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('uuid', $user->uuid);
    });

    it('logs out and invalidates the token', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        // The personal access token should be deleted.
        expect($user->fresh()->tokens)->toBeEmpty();
    });

    it('rejects unauthenticated access', function (): void {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    });
});

describe('profile management', function (): void {
    it('updates the user profile', function (): void {
        $user = Sanctum::actingAs(User::factory()->create([
            'first_name' => 'Old',
            'email' => 'old@example.com',
        ]));

        $this->patchJson('/api/v1/auth/me', [
            'first_name' => 'New Name',
        ])->assertOk()->assertJsonPath('first_name', 'New Name');

        expect($user->fresh()->first_name)->toBe('New Name');
    });

    it('prevents duplicate email on profile update', function (): void {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/auth/me', ['email' => 'taken@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    });

    it('changes the password with valid current password', function (): void {
        $user = User::factory()->create(['password' => 'oldpass123']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'oldpass123',
            'password' => 'newpass456',
            'password_confirmation' => 'newpass456',
        ])->assertOk()->assertJsonPath('message', 'Password changed successfully.');

        expect(Hash::check('newpass456', $user->fresh()->password))->toBeTrue();
    });

    it('rejects password change with wrong current password', function (): void {
        $user = User::factory()->create(['password' => 'oldpass123']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrongpassword',
            'password' => 'newpass456',
            'password_confirmation' => 'newpass456',
        ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
    });

    it('rejects same password on change', function (): void {
        $user = User::factory()->create(['password' => 'samepass1']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'samepass1',
            'password' => 'samepass1',
            'password_confirmation' => 'samepass1',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    });
});
