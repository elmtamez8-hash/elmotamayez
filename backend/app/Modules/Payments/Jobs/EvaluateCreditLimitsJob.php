<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\EvaluateCreditLimit;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The demotion half of FR-037 — the only half a clock can see.
 *
 * A raise is earned by an event (a payment landed on time) and is applied where
 * that event happens. Falling behind is the ABSENCE of one: nothing fires on the
 * fourteenth day of owing, so the passage of time has to be asked about.
 *
 * ⚠️ THE CUT-OFF IS PUSHED INTO SQL, not filtered in PHP. `negative_since` is the
 * second column of `(workspace_id, negative_since)`, and the whole point of
 * storing the date was to avoid reading every balance to find the few that are
 * late. A `->get()` then a filter would restore exactly the scan the column was
 * added to remove.
 */
class EvaluateCreditLimitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(WorkspaceContext $context, EvaluateCreditLimit $action, BillingSettings $settings): void
    {
        // The window is the platform's, so it is read once for the whole sweep
        // rather than per balance — and the Action re-checks it anyway, which is
        // what keeps this query a pre-filter rather than the decision.
        $cutoff = now()->subDays($settings->decreaseAfterLateDays());

        $balances = CreditBalance::query()
            ->withoutWorkspaceScope()
            ->whereNotNull('negative_since')
            ->where('negative_since', '<=', $cutoff)
            ->get(['id', 'workspace_id']);

        foreach ($balances as $row) {
            $workspace = Workspace::query()->find($row->workspace_id);

            if ($workspace === null) {
                continue;
            }

            // forWorkspace, never set(): WorkspaceContext is an application-wide
            // singleton that caches its resolution, so a direct set here leaks
            // this workspace into whatever the same worker handles next.
            $context->forWorkspace($workspace, function () use ($row, $action): void {
                $balance = CreditBalance::query()->whereKey((int) $row->getKey())->first();

                if ($balance !== null) {
                    $action->handle($balance);
                }
            });
        }
    }
}
