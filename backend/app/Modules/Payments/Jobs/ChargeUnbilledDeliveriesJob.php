<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Payments\Actions\ChargeSessionSeats;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sessions that were delivered and never charged, charged.
 *
 * Not a nicety. `CloseClassSession` returns early on a terminal status, so
 * `SessionDelivered` fires exactly ONCE in a session's life — a queue outage, a
 * worker killed mid-listener or a throw in an earlier synchronous listener
 * leaves that session unbilled forever, with no second dispatch to repair it.
 * And because withholding is derived from the balance rather than stored, the
 * student's record stays clean and they carry on booking (research › R17).
 *
 * A sweep rather than a retry, for the same reason ReleasePendingUnitsJob is
 * one: what it repairs is the ABSENCE of an event, and nothing fires when
 * nothing happened.
 *
 * Safe to run twice by construction — every entry carries the unique key
 * (balance, type, source_type, session id), so a second pass over a charged
 * session writes nothing.
 */
class ChargeUnbilledDeliveriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WorkspaceContext $context, ChargeSessionSeats $action): void
    {
        // Leading on charged_at, which is the index's leading column and the
        // selective side: almost every session is charged.
        $sessions = ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereNull('charged_at')
            ->whereNotNull('delivered_at')
            ->get(['id', 'workspace_id']);

        foreach ($sessions as $row) {
            $workspace = Workspace::query()->find($row->workspace_id);

            if ($workspace === null) {
                continue;
            }

            // forWorkspace, never set(): WorkspaceContext is an application-wide
            // singleton that caches its resolution, so a direct set here leaks
            // this workspace into whatever the same worker handles next.
            $context->forWorkspace($workspace, function () use ($row, $action): void {
                $session = ClassSession::query()->whereKey((int) $row->getKey())->first();

                if ($session === null) {
                    return;
                }

                // The frozen count off the row, exactly as the event carried it.
                // Null means the cancellation deadline never ran, and zero is the
                // honest reading of that — never a live count of the bookings,
                // which FR-060 forbids and which would bill a seat released
                // weeks ago.
                $action->handle($session, $session->billable_seats ?? 0);
            });
        }
    }
}
