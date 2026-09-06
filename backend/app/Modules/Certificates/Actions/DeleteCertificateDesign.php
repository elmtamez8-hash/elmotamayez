<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Actions;

use App\Modules\Certificates\Models\CertificateDesign;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removing a design a teacher no longer wants.
 *
 * ⚠️ NO ISSUED CERTIFICATE BREAKS (`FR-017` · `FR-045`), and not because anything
 * repairs them: a certificate stores no design at all. It resolves one at every
 * verification, so deleting the selected row simply moves every certificate in the
 * workspace onto the shipped default — including the ones already printed and
 * hanging on a wall.
 */
class DeleteCertificateDesign extends Action
{
    public function handle(CertificateDesign $design): void
    {
        $path = $design->image_path;

        DB::transaction(function () use ($design): void {
            /*
            | ⚠️ THE SELECTION IS CLEARED FIRST. `selected_for_workspace_id` is
            | UNIQUE, and a row deleted while still holding it is fine — but doing
            | it in this order keeps the invariant true at every moment, which is
            | what the next reader will assume.
            */
            $design->forceFill(['selected_for_workspace_id' => null])->save();
            $design->delete();
        });

        /*
        | ⚠️ THE FILE GOES TOO, AND AFTER THE ROW, NEVER BEFORE. `SaveAccountPhoto`
        | wrote the rule down: an image nothing points at is storage nobody ever
        | reads. Deleting the file first and then failing the row would leave a
        | design that exists, is listed, and shows a broken frame.
        */
        if (is_string($path) && $path !== '') {
            Storage::disk('public')->delete($path);
        }
    }
}
