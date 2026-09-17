<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\DeviceRegistry;
use App\Shared\Actions\Action;
use Illuminate\Http\Request;

/**
 * Records a sign-in that arrived through the panel door, so it is a device like
 * any other: listed in «أجهزتي», counted by the limit, and endable.
 *
 * ⚠️ WHAT THIS CLOSES. Until spec 037 · story 2 the panel was the one way into
 * the platform that left no trace: `/admin` authenticates against the `web` guard
 * and never touches `StartAuthSession`, so a teacher who opened their panel had a
 * live sign-in that `auth_sessions` had never heard of — absent from the list,
 * outside the count, and impossible to end. The screen said it showed every device
 * and quietly showed one fewer.
 *
 * ⚠️ NO TOKEN, DELIBERATELY. The handle is the session id; see the migration that
 * added the column. `StartAuthSession` mints a bearer token because the API rests
 * on one — minting one here would be an API credential created by opening a panel.
 *
 * ⚠️ AND THE SESSION ID IS READ AFTER `SessionGuard::login()`, WHICH IS THE ONLY
 * MOMENT IT IS THE FINAL ONE. `login()` calls `updateSession()` — `regenerate(true)`
 * — BEFORE firing the `Login` event this Action hangs off, so the id here is the
 * post-regeneration one the browser will actually carry. Any further `regenerate()`
 * after the login call silently strands this row on a session that no longer
 * exists: the row would look healthy and end nothing. `PanelHandoffController` had
 * exactly such a line and it was removed with the reason written in its place, and
 * `PanelSessionRecordedTest` fails if it comes back.
 */
class RecordPanelSession extends Action
{
    public function __construct(private readonly DeviceRegistry $devices) {}

    public function handle(User $user, Request $request, string $sessionId): AuthSession
    {
        $device = $this->devices->resolve($user, $request);

        $session = AuthSession::query()->create([
            'user_id' => $user->getKey(),
            'device_id' => $device->getKey(),
            'token_id' => null,
            'session_id' => $sessionId,
            'status' => AuthSession::STATUS_ACTIVE,
            'ip_hash' => $request->ip() === null ? null : hash('sha256', $request->ip()),
            'last_active_at' => now(),
        ]);

        $this->devices->enforceLimit($user, $device, $session);

        return $session;
    }
}
