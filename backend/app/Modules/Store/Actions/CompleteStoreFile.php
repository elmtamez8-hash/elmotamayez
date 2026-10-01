<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaLimits;
use App\Modules\Store\Models\StoreItem;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Settle a product file's upload. Only THIS item's asset answers — another
 * item's or a lesson's reads as missing, so the route reveals nothing about
 * files it does not own. The caller has asked `StoreItemPolicy::update()`.
 */
class CompleteStoreFile extends Action
{
    public function __construct(private readonly CompleteMediaUpload $complete) {}

    public function handle(StoreItem $item, string $assetUuid): MediaAsset
    {
        $asset = MediaAsset::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $assetUuid)
            ->where('owner_type', StoreItem::class)
            ->where('owner_id', $item->getKey())
            ->first();

        if (! $asset instanceof MediaAsset) {
            throw new ModelNotFoundException('لم نجد الملف. أعد رفعه.');
        }

        return $this->complete->handle($asset, MediaLimits::allowedMimeTypes(MediaKind::Document));
    }
}
