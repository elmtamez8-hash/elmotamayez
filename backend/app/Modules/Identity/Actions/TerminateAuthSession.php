<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\SessionEndReason;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\Session;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Ends a session, for real and immediately.
 *
 * Removing the CREDENTIAL is the enforcement — no middleware re-checks what the
 * guard already checks on the next request. The row stays behind: it is the audit
 * trail (FR-026) and the answer the sign-in screen gives someone who was
 * unexpectedly logged out.
 *
 * ⚠️ AND «the credential» IS ONE OF TWO, WHICH IS WHY BOTH BRANCHES RUN RATHER
 * THAN ONE OR THE OTHER. A sign-in through the API rests on a bearer token; a
 * sign-in through `/admin` rests on a session and has no token at all. Each row
 * carries exactly one of the two handles, both columns are nullable, and a branch
 * skipped is a device told it was evicted that never was.
 */
class TerminateAuthSession extends Action
{
    public function handle(AuthSession $session, SessionEndReason $reason): AuthSession
    {
        if (! $session->isActive()) {
            return $session;
        }

        if ($session->token_id !== null) {
            PersonalAccessToken::query()->whereKey($session->token_id)->delete();
        }

        /*
        | ⚠️ AND THE PANEL DOOR, WHICH HAS NO TOKEN TO DELETE (spec 037 · story 2).
        |
        | `/admin` signs in against the `web` guard, so a session opened there had
        | nothing this Action could reach: the row was written `ended`, the owner
        | was told the device was out, and the panel carried on. The same shape as
        | the hole `config/sanctum.php` closed on 2026-09-17, reached from the other
        | side — there a session authenticated the API, here it survived eviction.
        |
        | Destroying the session record is the eviction: the next request finds an
        | empty session, no `login_web_*` key in it, and is a guest. It is the
        | driver's own `destroy()` rather than anything of ours, so it is correct on
        | redis in production and on the array driver the suite runs.
        |
        | ⚠️ `getHandler()`, NEVER `Session::flush()` — that empties the session of
        | whoever is making THIS request, which is usually the person pressing the
        | button on a device they mean to keep.
        */
        if ($session->session_id !== null) {
            Session::getHandler()->destroy($session->session_id);
        }

        $session->forceFill([
            'status' => AuthSession::STATUS_ENDED,
            'ended_reason' => $reason,
            'ended_at' => now(),
        ])->save();

        return $session;
    }
}
