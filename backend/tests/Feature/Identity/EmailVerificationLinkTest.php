<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\URL;

/*
| The confirmation link is opened from a mail client: a browser tab that holds no
| token. Behind `auth:sanctum` it answered 401 to every new account the day real
| mail went live (2026-09-25). So every case here sends NO Authorization header —
| that is the whole point; a case that authenticated first would pass against the
| broken route.
*/

function verificationLink(User $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => $hash ?? sha1($user->getEmailForVerification()),
    ]);
}

it('confirms the address from a signed link with no login, then sends the person to the site', function (): void {
    $user = User::factory()->unverified()->create();

    $this->get(verificationLink($user))
        ->assertRedirect(config('cms.site_url').'/login?verified=1');

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

it('confirms nothing when the hash does not match the address', function (): void {
    $user = User::factory()->unverified()->create();

    $this->get(verificationLink($user, sha1('someone-else@example.com')))
        ->assertRedirect(config('cms.site_url').'/login?verified=0');

    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('refuses a link whose signature was tampered with', function (): void {
    $user = User::factory()->unverified()->create();
    $victim = User::factory()->unverified()->create();

    // Swapping the id inside a valid link must break the signature, which is what
    // makes the id safe to trust without a login.
    $forged = str_replace('/verify/'.$user->getKey().'/', '/verify/'.$victim->getKey().'/', verificationLink($user));

    $this->get($forged)->assertRedirect(config('cms.site_url').'/login?verified=0');

    expect($victim->refresh()->hasVerifiedEmail())->toBeFalse()
        ->and($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

/*
| ⛔ AN EXPIRED LINK WAS A RAW 403 PAGE (until 2026-09-27) — the `signed`
| middleware refused it before the controller ran, so a person opening
| yesterday's mail never reached the sign-in page that offers a new link. Still
| refused, still writes nothing; it only lands somewhere a person can act.
*/
it('sends an expired link to the sign-in page as unconfirmed, and confirms nothing', function (): void {
    $user = User::factory()->unverified()->create();
    $link = verificationLink($user);

    $this->travel(2)->hours();

    $this->get($link)->assertRedirect(config('cms.site_url').'/login?verified=0');

    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('refuses a link with no signature at all', function (): void {
    $user = User::factory()->unverified()->create();
    $unsigned = strtok(verificationLink($user), '?');

    $this->get($unsigned)->assertRedirect(config('cms.site_url').'/login?verified=0');

    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});
