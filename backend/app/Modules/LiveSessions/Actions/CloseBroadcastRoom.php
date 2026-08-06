<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Actions\Action;

/**
 * Shuts the room.
 *
 * `room_closed_at` is the fact that matters, not whatever the provider does:
 * the refusal to re-enter is enforced by `joinWindowCovers()` on our side, so a
 * provider that leaves its room open, or one swapped in later with different
 * semantics, cannot reopen a door we have closed (FR-015).
 *
 * Idempotent — CloseClassSession and an explicit "end" from the host both reach
 * here, and one after the other must not be an error.
 */
class CloseBroadcastRoom extends Action
{
    public function __construct(
        private readonly BroadcastProviderInterface $provider,
    ) {}

    public function handle(ClassSession $session): ClassSession
    {
        if ($session->room_closed_at !== null) {
            return $session;
        }

        $this->provider->closeRoom($session);

        $session->forceFill(['room_closed_at' => now()])->save();

        return $session;
    }
}
