<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use App\Shared\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Guard for models exposed on the public, cross-workspace marketplace.
 *
 * WHY THIS EXISTS — read before touching any public route.
 *
 * {@see WorkspaceScope} filters nothing when there is no authenticated user: it
 * reads WorkspaceContext::id(), gets null for a guest, and returns early without
 * adding a single condition. Every unauthenticated query therefore sees *all*
 * workspaces by default.
 *
 * publiclyListed() is the replacement guard. A public query without it leaks
 * every workspace's data, so it is not optional and not a performance tweak.
 *
 * Three conditions, all required:
 *   1. the row itself is published (model-specific, see publicListingConstraints)
 *   2. the owning workspace opted into the marketplace
 *   3. any model-specific state gate (e.g. a teacher must be approved)
 *
 * ⚠️ AND IT DROPS {@see WorkspaceScope}, WHICH IT DID NOT UNTIL 2026-09-03.
 *
 * The reasoning above stops at the guest and that was the blind spot: a public
 * page is also opened by people who are SIGNED IN. For them the context resolves
 * — WorkspaceContext::id() falls back to `users.last_workspace_id` — so the
 * global scope ANDs their own workspace onto a query that is meant to be
 * cross-tenant by definition. A signed-in teacher browsing the marketplace got
 * **404 on every course and every profile belonging to anybody else**, and the
 * marketplace listings silently shrank to their own rows.
 *
 * It was invisible to the suite because a public read is normally asserted as a
 * guest, and to a guest the scope contributes nothing — the two answers agree
 * on exactly the case everybody tests.
 *
 * The bypass belongs HERE and not at each call site: fourteen queries reach this
 * scope, and `CMS\Models\Article` had already hit the defect and fixed it in its
 * own line with a comment explaining why. One call site fixed and thirteen not
 * is the two-spellings shape this repository has paid for repeatedly — and the
 * bypass is safe precisely because the three conditions above are what actually
 * decide visibility. Tenant isolation is not what protects a public page; this
 * scope is.
 */
trait IsPubliclyListed
{
    /**
     * Restrict a query to rows that may be shown to an anonymous visitor.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePubliclyListed(Builder $query): Builder
    {
        // The declared bypass — see the class docblock. A public read is
        // cross-tenant by definition, and leaving the scope on answers 404 to
        // every signed-in visitor looking at somebody else's page.
        $query->withoutGlobalScope(WorkspaceScope::class);

        $query->whereExists(function ($sub) use ($query): void {
            $sub->selectRaw('1')
                ->from('workspaces')
                ->whereColumn('workspaces.id', $query->getModel()->getTable().'.workspace_id')
                ->where('workspaces.participates_in_marketplace', true);
        });

        return $this->publicListingConstraints($query);
    }

    /**
     * Model-specific conditions for being publicly visible.
     *
     * Implement on every model using this trait. Returning the query untouched is
     * always wrong: workspace participation alone would publish drafts.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    abstract protected function publicListingConstraints(Builder $query): Builder;
}
