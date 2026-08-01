<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Modules\Tenancy\Models\Workspace;

/**
 * The workspace independent teachers belong to (FR-013).
 *
 * Constitution I says every tenant-owned row has a workspace, with no exceptions
 * — so a teacher who applied directly rather than through an academy still needs
 * one. Rather than weaken the rule for a single case, they all land here.
 *
 * It participates in the marketplace by definition: an independent teacher who
 * applied to the platform has no academy to opt in on their behalf, and a
 * non-participating home workspace would make approval a no-op.
 */
final class PlatformWorkspace
{
    public static function resolve(): Workspace
    {
        /** @var string $slug */
        $slug = config('marketplace.platform_workspace.slug');

        $workspace = Workspace::query()->where('slug', $slug)->first();

        if ($workspace !== null) {
            return $workspace;
        }

        $workspace = new Workspace;
        $workspace->forceFill([
            'name' => config('marketplace.platform_workspace.name'),
            'slug' => $slug,
            'type' => 'academy',
            'participates_in_marketplace' => true,
        ])->save();

        return $workspace;
    }
}
