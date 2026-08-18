<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Enums\HostAction;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\BroadcastProviderResolver;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Mute, remove, end.
 *
 * Who may do it is the policy's call; this is about whether the provider can —
 * for the two of the three that are actually the provider's to do.
 *
 * **Mute and remove** are claims about media the provider holds, so a capability
 * it never claimed throws rather than no-ops: a teacher pressing "mute" on a
 * microphone that stays open, with nothing saying so, is worse than a provider
 * that has no mute at all.
 *
 * **Ending is ours.** `room_closed_at` is what refuses re-entry (FR-015), and
 * the provider's `closeRoom()` is a teardown hook the only implemented provider
 * fulfils as a documented no-op. Sending "end" through `hostAction()` made the
 * teacher's one host button dead against every provider that does not claim
 * hostControls — which today is all of them: 501, the door never shut, and the
 * register left waiting for the scheduled sweep. `CloseBroadcastRoom` still
 * calls the provider, so a provider with a real teardown is not skipped.
 */
class PerformHostAction extends Action
{
    public function __construct(
        private readonly BroadcastProviderResolver $providers,
        private readonly CloseBroadcastRoom $closeRoom,
    ) {}

    public function handle(ClassSession $session, HostAction $action, ?User $target = null): void
    {
        if ($action->requiresTarget() && $target === null) {
            throw new DomainException('حدّد المشارك المقصود.');
        }

        if ($action === HostAction::End) {
            $this->closeRoom->handle($session);

            return;
        }

        $this->providers->for($session)->hostAction($session, $action, $target);
    }
}
