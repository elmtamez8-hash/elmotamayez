<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Actions\TerminateAuthSession;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\SessionEndReason;
use Illuminate\Auth\Events\Logout;

/**
 * Signing out of `/admin` ends the device row that signing in wrote.
 *
 * ⛔ Until now it did not: `RecordPanelSignIn` hangs off `Login` and nothing hung
 * off `Logout`, so an operator who pressed «تسجيل الخروج» kept a row reading
 * `active` on their devices screen and in the device count.
 *
 * ⚠️ THE FRAMEWORK'S EVENT, for the reason `RecordPanelSignIn` gives: Filament owns
 * the logout controller, and the API's cookie branch (`AuthController::logout()`)
 * logs the same guard out from a second place.
 *
 * ⚠️ NOT QUEUED. `SessionGuard::logout()` fires this BEFORE the caller invalidates
 * the session, so the id read here is still the one the row was written under;
 * a queued listener would have no session at all.
 */
class EndPanelSessionOnLogout
{
    public function __construct(private readonly TerminateAuthSession $terminate) {}

    public function handle(Logout $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        $session = AuthSession::query()
            ->active()
            ->whereNull('token_id')
            ->where('session_id', $request->session()->getId())
            ->first();

        if ($session !== null) {
            $this->terminate->handle($session, SessionEndReason::Logout);
        }
    }
}
