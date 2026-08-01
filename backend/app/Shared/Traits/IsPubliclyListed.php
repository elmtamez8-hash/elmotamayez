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
 * workspaces by default. The public marketplace does not need to bypass tenant
 * isolation — on a guest request there is no isolation left to bypass.
 *
 * publiclyListed() is the replacement guard. A public query without it leaks
 * every workspace's data, so it is not optional and not a performance tweak.
 *
 * Three conditions, all required:
 *   1. the row itself is published (model-specific, see publicListingConstraints)
 *   2. the owning workspace opted into the marketplace
 *   3. any model-specific state gate (e.g. a teacher must be approved)
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
