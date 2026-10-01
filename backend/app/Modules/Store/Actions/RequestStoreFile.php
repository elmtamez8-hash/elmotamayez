<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Models\User;
use App\Modules\Media\Data\UploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaLimits;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Modules\Store\Models\StoreItem;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Somewhere to put a digital product's file (the book, the PDF).
 *
 * ⚠️ THE STORE'S OWN DOOR, NOT THE LESSON'S — the same shape as
 * `RequestChatAttachment`. `media_assets.owner_*` are NOT NULL and the generic
 * `/media/assets/{asset}/complete` refuses anything that is not a lesson's, so a
 * product owns its upload: the item exists first (saved hidden), then its file.
 * `SaveStoreItem` accepts only an asset owned by that item and `Ready`.
 *
 * The caller has already asked `StoreItemPolicy::update()`.
 */
class RequestStoreFile extends Action
{
    public function __construct(private readonly MediaProviderResolver $providers) {}

    /** @return array{asset: MediaAsset, ticket: UploadTicket} */
    public function handle(User $uploader, StoreItem $item, string $originalFilename, ?int $declaredSizeBytes = null): array
    {
        // A printed book has no file to sell; reserving one would leave an asset
        // nothing can ever attach.
        if ($item->kind->isStocked()) {
            throw new DomainException('النسخة المطبوعة لا تحتاج ملفاً.');
        }

        if ($declaredSizeBytes !== null && $declaredSizeBytes > MediaLimits::maxSizeBytes(MediaKind::Document)) {
            throw new DomainException(MediaLimits::sizeRefusal(MediaKind::Document));
        }

        $provider = $this->providers->forKind(MediaKind::Document);

        $asset = new MediaAsset([
            'workspace_id' => $item->workspace_id,
            'owner_type' => StoreItem::class,
            'owner_id' => $item->getKey(),
            'uploaded_by_user_id' => $uploader->getKey(),
            // Stamped from whoever took it, never the config — see RequestChatAttachment.
            'provider' => $provider->identifier(),
            'kind' => MediaKind::Document,
            'role' => MediaRole::Attachment,
            // Handed over only through `IssueStoreAccess`, after a paid order.
            'is_downloadable' => false,
            'status' => MediaAssetStatus::Pending,
            'original_filename' => $originalFilename,
            'size_bytes' => $declaredSizeBytes,
        ]);

        $asset->save();

        return [
            'asset' => $asset,
            'ticket' => $provider->createUploadTicket($asset),
        ];
    }
}
