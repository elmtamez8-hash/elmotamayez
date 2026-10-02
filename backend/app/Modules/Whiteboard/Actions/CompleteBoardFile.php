<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Whiteboard\Models\Board;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Settle a board picture's upload: PNG or JPEG, nothing else — decided by
 * `CompleteMediaUpload` from the bytes, not the name. Only THIS board's asset
 * answers; any other reads as missing.
 */
final class CompleteBoardFile extends Action
{
    public const MIMES = ['image/png', 'image/jpeg'];

    public function __construct(private readonly CompleteMediaUpload $complete) {}

    public function handle(Board $board, string $assetUuid): MediaAsset
    {
        $asset = self::find($board, $assetUuid);

        if (! $asset instanceof MediaAsset) {
            throw new ModelNotFoundException('لم نجد الملف. أعد رفعه.');
        }

        return $this->complete->handle($asset, self::MIMES);
    }

    public static function find(Board $board, string $assetUuid): ?MediaAsset
    {
        return MediaAsset::query()
            ->withoutWorkspaceScope()
            ->where('uuid', $assetUuid)
            ->where('owner_type', Board::class)
            ->where('owner_id', $board->getKey())
            ->first();
    }
}
