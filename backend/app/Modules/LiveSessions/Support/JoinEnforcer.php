<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Models\User;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;
use Throwable;

/**
 * «Somebody just walked into a room — may they be there?», asked the moment the
 * broadcast provider says so (security scan 2026-10-10, F14).
 *
 * ⛔ WHY THIS EXISTS BESIDE THE HEARTBEAT. The provider cannot revoke a ticket,
 * and «إخراج» only disconnects; the presence beat's 403 is what makes the
 * BROWSER leave. A client that is not our page — the ticket pasted into any
 * other app — ignores the 403 and walks back in on the ticket it holds. So the
 * join itself is answered here, with the ONE admission rule both doors already
 * share (`RoomRevocation::stillAdmitted`), never a second copy of it.
 *
 * ⚠️ UNDER THE SESSION'S OWN WORKSPACE TEAM. The webhook has no signed-in user
 * and no current workspace, so spatie's team id is null and the host gate
 * (`sessions.host`) answers false for the TEACHER — who holds no seat — and the
 * teacher would be removed from their own lesson on every join.
 *
 * ⚠️ AN UNKNOWN ROOM OR IDENTITY IS LEFT ALONE. The recorder is a participant
 * too (`EG_…`), and the caller filters it by kind before this runs; anything
 * that is not one of our sessions and one of our users is not ours to evict.
 */
class JoinEnforcer
{
    public function __construct(
        private readonly RoomRevocation $revocation,
        private readonly BroadcastProviderResolver $providers,
    ) {}

    /** @return bool whether the participant was removed */
    public function joined(string $sessionUuid, string $identity): bool
    {
        $session = ClassSession::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $sessionUuid)
            ->first();

        $user = User::query()->where('uuid', $identity)->first();

        if ($session === null || $user === null) {
            return false;
        }

        if ($this->admits($session, $user)) {
            return false;
        }

        try {
            $this->providers->for($session)->hostAction($session, HostAction::Remove, $user);
        } catch (Throwable) {
            // Already gone, or the provider is down: the heartbeat is still the
            // second guard, and an error here must not become a 500 the provider
            // retries into a storm (live-sessions.md, «a provider error is an
            // ANSWER»).
            return false;
        }

        return true;
    }

    /** The shared admission rule, asked under the session's own workspace team. */
    public function admits(ClassSession $session, User $user): bool
    {
        return app(WorkspaceContext::class)->forWorkspace(
            (int) $session->workspace_id,
            fn (): bool => $this->revocation->stillAdmitted($session, $user),
        );
    }
}
