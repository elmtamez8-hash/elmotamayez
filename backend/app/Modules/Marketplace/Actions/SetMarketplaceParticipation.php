<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;

/**
 * A workspace opting into or out of the public marketplace (FR-001, FR-002).
 *
 * Withdrawing hides every teacher and course the workspace owns and touches no
 * teacher's approval_status. Withdrawal is the academy's decision about where its
 * listings appear, not a judgement on the people in them — and marking them
 * rejected would make re-joining a re-approval queue.
 *
 * Nothing needs to be written to the teachers themselves: publiclyListed() reads
 * this flag through a join, so one row changes and the whole workspace disappears.
 */
class SetMarketplaceParticipation extends Action
{
    public function handle(Workspace $workspace, bool $participates): Workspace
    {
        $workspace->forceFill(['participates_in_marketplace' => $participates])->save();

        MarketplaceCache::flush();

        return $workspace;
    }
}
