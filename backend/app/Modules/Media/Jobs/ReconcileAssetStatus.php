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
use Throwable;

/**
 * Asks the provider about assets still in flight.
 *
 * Polling rather than a webhook because a webhook's shape is specific to each
 * provider and there is no provider yet — this works with any of them, and a
 * webhook can later be a second way into the same reconciliation.
 *
 * ⚠️ IT WAS NEVER SCHEDULED UNTIL 019, AND THAT WAS INVISIBLE. Nothing reached
 * `Processing` and stayed there while the only provider settled an asset inside the
 * request that uploaded it. A provider that transcodes on its own clock makes
 * `Processing` a state something has to leave, and nothing was asking — so a
 * teacher's own upload would have sat «قيد التجهيز» for ever. Same family as the
 * retry loop 017 shipped: the sender existed, the second ask did not.
 *
 * ⚠️ AND IT ASKS ONLY ABOUT `Processing`, NOT `Uploading`. It used to name both.
 * `Uploading` means bytes are still arriving HERE, which is not a question for a
 * provider — and a 2 GiB PUT takes longer than the one-minute age below, so
 * settling one mid-transfer would read a partial file and could publish it as
 * complete. Since this job had never actually run, narrowing it breaks nothing and
 * removes a failure that scheduling it would otherwise have switched on.
 */
class ReconcileAssetStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(CompleteMediaUpload $complete): void
    {
        MediaAsset::query()
            ->withoutWorkspaceScope()
            ->where('status', MediaAssetStatus::Processing->value)
            ->where('updated_at', '<', now()->subMinute())
            ->orderBy('id')
            ->chunkById(50, function ($assets) use ($complete): void {
                foreach ($assets as $asset) {
                    $workspace = Workspace::query()->find($asset->workspace_id);

                    if ($workspace === null) {
                        continue;
                    }

                    /*
                     * ⚠️ ONE ROW MAY NOT TAKE THE PLATFORM'S RECONCILIATION WITH IT.
                     *
                     * The walk is ordered by id and the supervisor runs `tries: 1`,
                     * so an uncaught throw here fails the job and the schedule walks
                     * straight back into the same lowest-id row five minutes later —
                     * for ever. Every `Processing` asset BEHIND it is then never
                     * reconciled again, platform-wide, and this job is the only
                     * rescue for a recording whose ingest budget ran out. Three real
                     * throw sources reach this line: an asset whose `provider` column
                     * names a provider this deployment does not have, a `delete()`
                     * that answers neither 2xx nor 404 when a Ready asset is rejected
                     * on mime/size/duration, and PublishRecordingAsLesson, which is a
                     * SYNCHRONOUS listener and so throws back into this loop.
                     *
                     * Reported, never swallowed: the row is skipped this pass and
                     * picked up on the next, and the exception is what says which row.
                     */
                    try {
                        // forWorkspace, never set(): WorkspaceContext is an
                        // application-wide singleton that caches its answer, so a
                        // direct set here would leak this workspace into whatever
                        // the same worker picks up next.
                        app(WorkspaceContext::class)->forWorkspace(
                            $workspace,
                            fn () => $complete->handle($asset),
                        );
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
    }
}
