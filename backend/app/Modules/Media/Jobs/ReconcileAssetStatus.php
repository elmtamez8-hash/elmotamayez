<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Asks the provider about assets still in flight.
 *
 * Polling rather than a webhook because a webhook's shape is specific to each
 * provider and there is no provider yet — this works with any of them, and a
 * webhook can later be a second way into the same reconciliation.
 */
class ReconcileAssetStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(CompleteMediaUpload $complete): void
    {
        MediaAsset::query()
            ->withoutWorkspaceScope()
            ->whereIn('status', [
                MediaAssetStatus::Uploading->value,
                MediaAssetStatus::Processing->value,
            ])
            ->where('updated_at', '<', now()->subMinute())
            ->orderBy('id')
            ->chunkById(50, function ($assets) use ($complete): void {
                foreach ($assets as $asset) {
                    $workspace = Workspace::query()->find($asset->workspace_id);

                    if ($workspace === null) {
                        continue;
                    }

                    // forWorkspace, never set(): WorkspaceContext is an
                    // application-wide singleton that caches its answer, so a
                    // direct set here would leak this workspace into whatever
                    // the same worker picks up next.
                    app(WorkspaceContext::class)->forWorkspace(
                        $workspace,
                        fn () => $complete->handle($asset),
                    );
                }
            });
    }
}
