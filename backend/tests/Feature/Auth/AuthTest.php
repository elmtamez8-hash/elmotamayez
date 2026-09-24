<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\RegisterAccount;
use App\Modules\Identity\Data\RegisterAccountData;
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
    | ⛔ AND SINCE 2026-09-24 THE INVITATION IS REQUIRED TOO (owner decision).
    | It was optional for one reason — the academy founder, who registered with
    | nothing in hand — and spec 025 · FR-026 abolished new founders, so the
    | optionality served nobody and left `/register` a side door around the
    | guardian gate. The middleware stays; the token is now a condition as well
    | as a claim.
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
    | ⛔ INVERTED ON 2026-09-24. This case used to assert the founder's door stayed
    | open with no invitation; spec 025 closed the founder path one request later,
    | so what stayed open was only a role-less account for anyone who typed the
    | URL. The row COUNT is the assertion — a 422 over a written row is a refusal
    | on paper only.
    */
    it('refuses a registration with no invitation, and writes no row', function (): void {
        $before = User::query()->count();

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jane',
            'email' => 'founder@academy.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['invitation'])
            ->assertJsonPath('errors.invitation.0', __('validation.custom.invitation.required'));

        expect(User::query()->count())->toBe($before);
    });

    it('refuses an empty invitation the same way', function (): void {
        $before = User::query()->count();

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jane',
            'email' => 'founder@academy.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invitation' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['invitation']);

        expect(User::query()->count())->toBe($before);
    });

    /*
    | The Action refuses on its own too: a seeder or a panel reaching it with no
    | form in front cannot mint a role-less account with an empty token.
    */
    it('refuses an empty token at the Action as well as at the request', function (): void {
        $before = User::query()->count();

        expect(fn () => app(RegisterAccount::class)->handle(RegisterAccountData::fromArray([
            'first_name' => 'Jane',
            'email' => 'founder@academy.test',
            'password' => 'password123',
        ])))->toThrow(DomainException::class);

        expect(User::query()->count())->toBe($before);
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
            ->assertJsonValidationErrors(['first_name', 'email', 'password', 'invitation']);
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
