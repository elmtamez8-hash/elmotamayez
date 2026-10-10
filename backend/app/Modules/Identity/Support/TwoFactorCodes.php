<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

/**
 * TOTP secrets and recovery codes, for both surfaces at once.
 *
 * Everything here delegates to the provider Filament's panel already uses, so a
 * teacher who enrols through the API can sign in to /admin with the same
 * authenticator entry — one secret, one QR code, no "which one did I set up?".
 * Writing our own would produce two enrolments for one person (research §R9).
 *
 * Two methods on that provider read `Filament::auth()->user()`, which is null
 * outside a panel request — so code verification goes straight to Google2FA with
 * the provider's own window, and the QR image is not produced here at all: the
 * API returns the `otpauth://` URI and the browser renders it.
 */
final class TwoFactorCodes
{
    /**
     * ±2 steps of 30 seconds, and `AdminPanelProvider` says the same. Filament's
     * default is 8 — seventeen codes valid at once, each for ~8.5 minutes
     * (security scan 2026-10-10, F5). Two still forgives a phone a minute off.
     */
    public const WINDOW = 2;

    public function __construct(
        private readonly AppAuthentication $provider,
        private readonly Google2FA $google2FA,
    ) {}

    /**
     * A 160-bit secret — 32 base32 characters.
     *
     * ⚠️ NOT the provider's `generateSecret()`, which returns 16 characters (80
     * bits). Google Authenticator REFUSES that on manual entry with "the key
     * value is too short": it requires at least 128 bits, and RFC 4226 §4
     * recommends 160. The QR path happened to work, so the defect only showed
     * for the person who types the key in by hand — which is exactly the person
     * whose camera or phone would not do it for them.
     *
     * Length is the only thing that changes. Verification still goes through the
     * provider's window, /admin still reads the same column, and a secret already
     * enrolled keeps working: TOTP does not care how long the shared key is, only
     * that both sides hold the same one.
     */
    public function generateSecret(): string
    {
        return $this->google2FA->generateSecretKey(32);
    }

    /**
     * The `otpauth://` URI, which is what the QR code encodes.
     *
     * Returned instead of an image so no picture of a secret is generated,
     * cached or logged server-side, and so the same string works for a person
     * who types the key in by hand.
     */
    public function provisioningUri(User $user, #[SensitiveParameter] string $secret): string
    {
        return $this->google2FA->getQRCodeUrl(
            (string) config('app.name'),
            $user->email,
            $secret,
        );
    }

    /**
     * ⛔ ONCE PER CODE, AND NEVER AN EARLIER ONE (security scan 2026-10-10, F5).
     * `verifyKey()` alone accepted the same code again for its whole window, so a
     * code seen over a shoulder signed in AND switched two-factor off. RFC 6238:
     * a success rejects that timestep and every one before it. The cache key is
     * Filament's own (`AppAuthentication::verifyCode`), so a code spent at /admin
     * is spent here too, and the reverse.
     */
    public function verify(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code): bool
    {
        $cacheKey = 'filament.app_authentication_codes.'.md5($secret);

        $verify = function () use ($cacheKey, $secret, $code): bool {
            $timestamp = $this->google2FA->verifyKeyNewer($secret, $code, Cache::get($cacheKey), self::WINDOW);

            if ($timestamp === false) {
                return false;
            }

            if ($timestamp === true) {
                $timestamp = $this->google2FA->getTimestamp();
            }

            Cache::put($cacheKey, $timestamp, (self::WINDOW + 1) * 60);

            return true;
        };

        // Two requests carrying one code must not both read the timestep first.
        return Cache::getStore() instanceof LockProvider
            ? (bool) Cache::lock("{$cacheKey}.lock", 10)->block(10, $verify)
            : $verify();
    }

    /**
     * Plain codes — the only moment they exist in readable form.
     *
     * @return array<int, string>
     */
    public function generateRecoveryCodes(): array
    {
        return $this->provider->generateRecoveryCodes();
    }

    /** @param  array<int, string>|null  $codes */
    public function storeRecoveryCodes(User $user, #[SensitiveParameter] ?array $codes): void
    {
        // Hashes each code on the way in. Recovery codes are passwords with one
        // use, and are stored like passwords.
        $this->provider->saveRecoveryCodes($user, $codes);
    }

    /**
     * True once, per code. The provider removes the matched code under a lock,
     * so two simultaneous attempts cannot both spend the same one.
     */
    public function consumeRecoveryCode(User $user, #[SensitiveParameter] string $code): bool
    {
        if ($user->getAppAuthenticationRecoveryCodes() === null) {
            return false;
        }

        return $this->provider->verifyRecoveryCode($code, $user);
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->getAppAuthenticationRecoveryCodes() ?? []);
    }
}
