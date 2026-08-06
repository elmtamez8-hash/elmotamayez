<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Mute, remove, end.
 *
 * Who may do it is the policy's call; this is about whether the provider can.
 * A capability it never claimed throws rather than no-ops — a teacher pressing
 * "mute" on a microphone that stays open, with nothing saying so, is worse than
 * a provider that has no mute at all.
 */
class PerformHostAction extends Action
{
    public function __construct(
        private readonly BroadcastProviderInterface $provider,
        private readonly CloseBroadcastRoom $closeRoom,
    ) {}

    public function handle(ClassSession $session, HostAction $action, ?User $target = null): void
    {
        if ($action->requiresTarget() && $target === null) {
            throw new DomainException('حدّد المشارك المقصود.');
        }

        $this->provider->hostAction($session, $action, $target);

        if ($action === HostAction::End) {
            // Ending is not only a provider call: the room has to be shut on our
            // side too, or an earlier ticket still opens it.
            $this->closeRoom->handle($session);
        }
    }
}
