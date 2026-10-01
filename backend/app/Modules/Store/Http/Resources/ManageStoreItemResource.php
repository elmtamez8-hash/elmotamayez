<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Support\StoreSettings;
use Illuminate\Http\Request;

/**
 * The teacher's view of a product: the buyer's fields plus what only the shelf
 * needs — the commission rate, the prices in major units for the form to fill
 * from (it never converts), and the uploaded file's state.
 *
 * ⚠️ `commission_bps` LIVES HERE AND NOT ON THE BUYER'S RESOURCE. It used to be
 * sent with the buyer's catalogue too: the platform's cut of a price is the
 * teacher's business, not the student's.
 *
 * @mixin StoreItem
 */
class ManageStoreItemResource extends StoreItemResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'commission_bps' => StoreSettings::commissionBps(),
            'price' => $this->price,
            'shipping_fee' => $this->shipping_fee,
            'file' => $this->whenLoaded('mediaAsset', fn (): ?array => $this->mediaAsset instanceof MediaAsset ? [
                'uuid' => $this->mediaAsset->uuid,
                'name' => $this->mediaAsset->original_filename,
                'status' => $this->mediaAsset->status->value,
            ] : null),
        ];
    }
}
