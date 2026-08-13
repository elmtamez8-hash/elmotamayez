<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Actions\ReconcilePayments;
use App\Modules\Payments\Contracts\PaymentProviderInterface;
use App\Modules\Payments\Providers\PaymentProviderRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The nightly ask: did anything settle that never reached us?
 *
 * ⚠️ A JOB, NEVER A GET. It calls out to every registered provider for a window
 * of transactions and then runs a join over the two tables that grow with every
 * sale. On a request that would happen whenever an operator opened the screen,
 * and twice if they refreshed — which is why the endpoint reads the stored run
 * and computes nothing.
 *
 * ⚠️ NO `WorkspaceContext::set()` ANYWHERE BELOW IT. The sweep spans every
 * workspace by construction, and the context is an application-wide singleton
 * that caches its resolution — a workspace set inside a worker leaks into
 * whatever that same worker handles next. Nothing in {@see ReconcilePayments}
 * needs one: every read states `withoutWorkspaceScope()`, and the workspace on a
 * reconciled callback is resolved from the ORDER it names, never from ambient
 * state.
 *
 * ⚠️ AND `withoutOverlapping()` IS ON THE SCHEDULE, NOT ON THIS CLASS. The
 * scheduler's lock expires by itself after 1440 minutes; the job-middleware lock
 * does not expire at all. A worker killed at its 900-second timeout would leave
 * a permanent lock behind and the reconciliation would never run again — silently,
 * which is the worst possible failure for the thing whose job is noticing
 * silence.
 */
class ReconcilePaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ⚠️ THE ENUMERATION LIVES HERE, NOT IN THE ACTION. `ProviderExtensibilityTest`
     * fails the build over an Action that names the registry at all: resolving a
     * provider by string carries the same coupling the interface exists to
     * remove. Every other Action gets its provider from the transaction it
     * belongs to — the sweep has no transaction to start from, so this job
     * resolves them and passes them in.
     */
    public function handle(ReconcilePayments $action, PaymentProviderRegistry $registry): void
    {
        $action->handle(array_map(
            fn (string $identifier): PaymentProviderInterface => $registry->get($identifier),
            $registry->identifiers(),
        ));
    }
}
