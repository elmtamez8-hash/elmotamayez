<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Jobs;

use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Support\ComplianceSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Delete the archives whose links have expired (FR-018).
 *
 * ⚠️ WITHOUT THIS THE FILES ACCUMULATE FOR EVER, and each one is "everything the
 * platform knows about one minor, in a single object". A link that stops working
 * while the file behind it stays on disk is a shorter promise than it looks: the
 * expiry is enforced at the route, and the object is one misconfigured disk away
 * from being served directly.
 *
 * ⚠️ AND THE FILE GOES BEFORE THE COLUMNS ARE CLEARED. The other order loses the
 * only pointer to the file if the delete fails halfway — an orphan nothing will
 * ever look for again, which is the one outcome worse than keeping it.
 *
 * The REQUEST row survives, deliberately: FR-026 wants a record that a request was
 * made and answered. What is deleted is the copy of the data, not the audit of it.
 */
class PruneExpiredExportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BATCH = 200;

    public function handle(): void
    {
        $disk = Storage::disk(ComplianceSettings::exportDisk());

        $expired = DataRequest::query()
            ->whereNotNull('export_path')
            ->whereNotNull('export_expires_at')
            ->where('export_expires_at', '<', now())
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($expired as $request) {
            $path = (string) $request->export_path;

            if ($path !== '' && $disk->exists($path)) {
                $disk->delete($path);
            }

            $request->forceFill([
                'export_path' => null,
                'export_expires_at' => null,
            ])->save();
        }
    }
}
