<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Models\MediaAsset;
use App\Shared\Actions\Action;

/**
 * The view-only / allow-download switch.
 *
 * It is one boolean, and it lives on the server for the reason the whole module
 * exists: hiding a download button in the browser is not a restriction. The
 * decision is read in `PlaybackController::stream()` and turned into a
 * `Content-Disposition` header, on every range request, next to the guard that
 * already decides whether the viewer may have the bytes at all.
 *
 * What it is NOT is copy protection, and the editor says so out loud. A document
 * served `inline` is still a document the reader's browser has decoded; what
 * "view only" buys is that the URL expires, so it cannot be forwarded. Selling
 * it as anything stronger is selling a promise the web does not keep.
 */
class SetAssetDisposition extends Action
{
    public function handle(MediaAsset $asset, bool $isDownloadable): MediaAsset
    {
        $asset->forceFill(['is_downloadable' => $isDownloadable])->save();

        return $asset;
    }
}
