<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Jobs;

use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Settlement\Actions\ReleasePendingUnits;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sweeps waiting units and releases the ones whose package has arrived.
 *
 * A sweep rather than a listener on the recording pipeline, on purpose. The
 * release depends on a state that gets there by several routes — the ingest job
 * succeeding, the publish listener running, the ingest exhausting its retries —
 * and subscribing to each of them would put a copy of this decision beside each
 * one. It also means the "failed" branch has something to notice it at all: a
 * failure is the absence of an event, and nothing is dispatched when a recording
 * never comes.
 */
class ReleasePendingUnitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WorkspaceContext $context, ReleasePendingUnits $action): void
    {
        // Distinct sessions holding waiting units. Reading the units first rather
        // than every recent session keeps the sweep proportional to the backlog
        // instead of to the calendar.
        $rows = TeachingUnit::query()
            ->withoutWorkspaceScope()
            ->where('status', TeachingUnitStatus::PendingPackage)
            ->get(['workspace_id', 'class_session_id'])
            ->unique(fn (TeachingUnit $unit): string => $unit->workspace_id.':'.$unit->class_session_id);

        foreach ($rows as $row) {
            $workspace = Workspace::query()->find($row->workspace_id);

            if ($workspace === null) {
                continue;
            }

            // forWorkspace, never set(): WorkspaceContext is an application-wide
            // singleton that caches its resolution, so a direct set here leaks
            // this workspace into whatever the same worker handles next.
            $context->forWorkspace($workspace, function () use ($row, $action): void {
                $session = ClassSession::query()->find($row->class_session_id);

                if ($session !== null) {
                    $action->handle($session);
                }
            });
        }
    }
}
