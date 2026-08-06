<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Closes a session at its scheduled end, whether or not anyone remembered to.
 *
 * A teacher who simply shuts their laptop must not leave a session live forever,
 * with its register unfinished and its report never sent. The Action it calls is
 * idempotent, so an explicit "end" from the host beforehand makes this a no-op.
 */
class CloseClassSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $classSessionId,
    ) {}

    public function handle(WorkspaceContext $context, CloseClassSession $action): void
    {
        $session = ClassSession::query()->withoutWorkspaceScope()->find($this->classSessionId);

        if ($session === null || $session->status->isTerminal()) {
            return;
        }

        $context->forWorkspace((int) $session->workspace_id, fn () => $action->handle($session));
    }
}
