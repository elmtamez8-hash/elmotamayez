<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Media\Data\UploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaLimits;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\BoardLock;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Shared\Actions\Action;

/**
 * Somewhere to put a picture placed on a board (US3) — the board's own door, the
 * shape of `RequestStoreFile`: `media_assets.owner_*` are NOT NULL and the
 * generic `/media/assets/{asset}/complete` refuses anything not a lesson's.
 *
 * A picture is a `Document`, as a chat picture is: the kinds are lesson kinds,
 * and the PNG/JPEG list is enforced on the BYTES at `complete`. Only the lock
 * holder uploads. The caller has asked `BoardPolicy::update()`.
 */
final class RequestBoardFile extends Action
{
    public function __construct(
        private readonly MediaProviderResolver $providers,
        private readonly BoardLock $lock,
    ) {}

    /** @return array{asset: MediaAsset, ticket: UploadTicket} */
    public function handle(User $uploader, Board $board, string $tab, string $filename, int $sizeBytes): array
    {
        $this->lock->assertHeldBy($board, (int) $uploader->getKey(), $tab);

        if ($sizeBytes > MediaLimits::maxSizeBytes(MediaKind::Document)) {
            throw new WhiteboardRefusal('too_large');
        }

        $provider = $this->providers->forKind(MediaKind::Document);

        $asset = new MediaAsset([
            'workspace_id' => $board->workspace_id,
            'owner_type' => Board::class,
            'owner_id' => $board->getKey(),
            'uploaded_by_user_id' => $uploader->getKey(),
            'provider' => $provider->identifier(),
            'kind' => MediaKind::Document,
            'role' => MediaRole::Attachment,
            'is_downloadable' => false,
            'status' => MediaAssetStatus::Pending,
            'original_filename' => $filename,
            'size_bytes' => $sizeBytes,
        ]);
        $asset->save();

        return ['asset' => $asset, 'ticket' => $provider->createUploadTicket($asset)];
    }
}
