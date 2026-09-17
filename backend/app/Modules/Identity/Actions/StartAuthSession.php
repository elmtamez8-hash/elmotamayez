<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Exceptions\PendingGuardianConsentException;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\DeviceRegistry;
use App\Modules\Identity\Support\UserStatus;
use App\Shared\Actions\Action;
use Illuminate\Http\Request;

/**
 * Signs a user in THROUGH THE API and mints the bearer token that identity rests
 * on. The panel door is {@see RecordPanelSession}.
 *
 * ⚠️ The device algorithm — new session first, older ones evicted after, and the
 * DEVICE counted rather than the session — moved to {@see DeviceRegistry} the day
 * that second door appeared, and the reasoning moved with it. Read it there; a
 * copy here would be the second spelling that ages.
 *
 * ⚠️ AND A TOKEN IS THIS ACTION'S OWN, NOT SOMETHING EVERY SIGN-IN NEEDS. A panel
 * sign-in must NOT mint one: it would be an API credential nobody asked for,
 * sitting in `personal_access_tokens` for the life of the account, authenticating
 * every API route if it ever leaked — and `config/sanctum.php` was emptied on
 * 2026-09-17 precisely to stop a panel session reaching the API at all.
 */
class StartAuthSession extends Action
{
    public function __construct(private readonly DeviceRegistry $devices) {}

    public function handle(User $user, Request $request, string $tokenName = 'auth-token'): AuthSessionResult
    {
        /*
        | ⚠️ SPEC 013 — REFUSED BEFORE A TOKEN IS MINTED, NEVER AFTER.
        |
        | A minor whose guardian has not consented gets no token at all. Minting
        | one and then restricting what it can reach means any flaw in the
        | restriction is a COMPLETE sign-in — the same reasoning `/auth/login`
        | already applies to a correct password on a two-factor account, and the
        | reason that path answers with a challenge instead of a limited token.
        |
        | Here rather than in the controller because this Action is the single
        | entrance every sign-in path shares: password, two-factor exchange, and
        | whatever is added next.
        */
        if ($user->status === UserStatus::PendingGuardianConsent->value) {
            throw new PendingGuardianConsentException;
        }

        $device = $this->devices->resolve($user, $request);

        $token = $user->createToken($tokenName);

        $session = AuthSession::query()->create([
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
            'token_id' => $token->accessToken->getKey(),
            'status' => AuthSession::STATUS_ACTIVE,
            'ip_hash' => $request->ip() === null ? null : hash('sha256', $request->ip()),
            'last_active_at' => now(),
        ]);

        $this->devices->enforceLimit($user, $device, $session);

        return new AuthSessionResult($session, $token->plainTextToken);
    }
}
