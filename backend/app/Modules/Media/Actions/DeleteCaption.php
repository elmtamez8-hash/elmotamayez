<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Models\MediaCaption;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the row and the file. Idempotent, like the asset delete beside it:
 * a retry after a timeout must not fail on a file that is already gone.
 */
class DeleteCaption extends Action
{
    public function handle(MediaCaption $caption): void
    {
        Storage::disk((string) config('media.disk'))->delete($caption->storage_path);

        $caption->delete();
    }
}
