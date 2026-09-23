<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/*
| «نسيت كلمة السر» end to end (audit 2026-09-23).
|
| Before: an existing address threw `RouteNotFoundException` (the mail builds
| `route('password.reset')`, which this API never defined), and an unknown one
| was answered «لا يوجد حساب بهذا البريد» — a 500 for members and an account
| oracle for everybody else.
*/

it('mails a reset link that opens the frontend page', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'amal@example.test']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'amal@example.test'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $mail) use ($user): bool {
        $url = $mail->toMail($user)->actionUrl;

        return str_starts_with($url, config('cms.site_url').'/reset-password?token=')
            && str_contains($url, 'email='.urlencode('amal@example.test'));
    });
});

it('answers an unknown address exactly as it answers a known one', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'amal@example.test']);

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'amal@example.test'])->assertOk();
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])->assertOk();

    expect($unknown->json())->toBe($known->json());
});

it('resets the password with the mailed token', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'amal@example.test']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'amal@example.test']);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'amal@example.test',
        'token' => $token,
        'password' => 'N3w-passw0rd!',
        'password_confirmation' => 'N3w-passw0rd!',
    ])->assertOk();

    $this->postJson('/api/v1/auth/login', ['email' => 'amal@example.test', 'password' => 'N3w-passw0rd!'])
        ->assertOk();
});
