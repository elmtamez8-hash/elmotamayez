<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Support\SessionEndReason;
use App\Shared\Actions\Action;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Ends a session, for real and immediately.
 *
 * Deleting the token IS the enforcement — Sanctum then rejects the next request
 * with a 401 on its own, so no middleware is needed to re-check what it already
 * checks. The row stays behind: it is the audit trail (FR-026) and the answer
 * the sign-in screen gives someone who was unexpectedly logged out.
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

        $session->forceFill([
            'status' => AuthSession::STATUS_ENDED,
            'ended_reason' => $reason,
            'ended_at' => now(),
        ])->save();

        return $session;
    }
}
