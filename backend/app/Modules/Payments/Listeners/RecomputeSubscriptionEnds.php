<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\LiveSessions\Events\FreezePeriodChanged;
use App\Modules\Payments\Support\EffectiveSubscriptionEnd;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A freeze moved; the subscriptions it covers move with it (T095 · FR-031).
 *
 * ⚠️ THREE OF THE FOUR RECOMPUTE MOMENTS LIVE HERE, and the third is the one
 * that gets forgotten: LIFTING a freeze has to take the extension back, or a
 * subscription keeps days it was granted for a reason that no longer exists.
 * The fourth — a subscription ACTIVATED inside a freeze already running — cannot
 * be reached from this event, because that period was written long ago; it is
 * done by `ActivateSubscription` itself.
 *
 * ⚠️ `ShouldHandleEventsAfterCommit`, because `CreateFreezePeriod` writes inside
 * a transaction and releases seats in the same breath. A queued job that started
 * before that commit would read the period as absent and compute an extension of
 * zero — reporting success, and leaving every subscription in the workspace one
 * freeze short with nothing to notice it.
 *
 * ⚠️ AND IT NEVER CALLS `WorkspaceContext::set()`. That singleton caches its
 * resolution, so a workspace set inside a queue worker leaks into whatever that
 * worker handles next. Nothing here needs a context at all: every read declares
 * `withoutWorkspaceScope()` and filters by the id the event carried.
 */
class RecomputeSubscriptionEnds implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private readonly EffectiveSubscriptionEnd $ends) {}

    public function handle(FreezePeriodChanged $event): void
    {
        $this->ends->recomputeForWorkspace($event->workspaceId, $event->studentUserId);
    }
}
