<?php

declare(strict_types=1);

namespace App\Modules\Community\Listeners;

use App\Modules\Community\Models\BlockedTerm;
use App\Modules\Community\Support\DefaultBlockedTerms;
use App\Modules\Tenancy\Events\WorkspaceCreated;

/**
 * A new teacher starts with a working filter rather than an empty one.
 *
 * ⚠️ REGISTERED IN `CommunityServiceProvider`, NEVER FOLDED INTO
 * `SeedDefaultRoles`. That is Tenancy's listener, and writing `blocked_terms`
 * from inside it would be one module reaching into another's table — Constitution
 * III, and the reason cross-module work goes through events at all. Two listeners
 * on one event is the shape the constitution asks for.
 *
 * ⚠️ AND THE WORKSPACE COMES FROM THE EVENT. `WorkspaceContext` is not the
 * workspace being created — creation happens inside whatever context the creator
 * was already in, and for a brand-new teacher that is null.
 *
 * Not queued: the rows are six inserts and a workspace with no filter, however
 * briefly, is a workspace whose first messages go unfiltered.
 */
class SeedDefaultBlockedTerms
{
    public function handle(WorkspaceCreated $event): void
    {
        $workspaceId = (int) $event->workspace->getKey();

        foreach (DefaultBlockedTerms::rows() as $term => $policy) {
            /*
            | ⚠️ `withoutWorkspaceScope()` HERE IS NOT TIDINESS — IT KEEPS THE
            | OWNER SIGNED IN. `WorkspaceScope::apply()` asks
            | `WorkspaceContext::id()`, and that singleton CACHES its answer for
            | the rest of the process. This listener runs while a workspace is
            | being created, with nobody authenticated yet, so a scoped read here
            | freezes the resolution at null — and the very next request finds no
            | team id, holds no role, and answers 403 to the owner on their own
            | workspace. It cost the invite test exactly that, and the failure
            | names permissions rather than this file.
            |
            | The workspace comes from the EVENT for the same reason: the context
            | is not the workspace being created.
            */
            BlockedTerm::query()
                ->withoutWorkspaceScope()
                ->firstOrCreate(
                    ['workspace_id' => $workspaceId, 'term' => $term],
                    ['policy' => $policy],
                );
        }
    }
}
