<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

describe('registration', function (): void {
    it('registers a new user successfully', function (): void {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'jane@example.com');

        expect(User::where('email', 'jane@example.com')->exists())->toBeTrue();
    });

    it('validates required fields', function (): void {
        $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'email', 'password']);
    });

    it('prevents duplicate email registration', function (): void {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jane',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
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
