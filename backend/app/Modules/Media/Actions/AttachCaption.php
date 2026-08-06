<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Modules\Media\Enums\CaptionKind;
use App\Modules\Media\Enums\CaptionSource;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Models\MediaCaption;
use App\Modules\Media\Support\WebVtt;
use App\Shared\Actions\Action;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores a WebVTT track next to its video.
 *
 * The file is parsed before it is written, not after: a track that fails to
 * parse shows the viewer nothing at all, and finding that out at playback time
 * means the teacher believes the lesson is captioned when it is not (FR-032).
 *
 * Same private disk as the video, and served through the grant — a captions file
 * on the public disk would be a permanent link to the lesson's entire script.
 */
class AttachCaption extends Action
{
    public function handle(
        MediaAsset $asset,
        UploadedFile $file,
        string $language = 'ar',
        CaptionKind $kind = CaptionKind::Captions,
        bool $isDefault = true,
    ): MediaCaption {
        $content = (string) $file->get();

        // Throws DomainException, which the exception handler renders as 422.
        WebVtt::parse($content);

        $disk = Storage::disk((string) config('media.disk'));
        $path = "captions/{$asset->uuid}/{$language}-{$kind->value}.vtt";

        $disk->put($path, $content);

        // Replaces rather than duplicates: the unique index is
        // (asset, language, kind), and re-uploading a corrected file is the
        // ordinary case.
        $caption = MediaCaption::query()->updateOrCreate(
            [
                'media_asset_id' => $asset->getKey(),
                'language' => $language,
                'kind' => $kind,
            ],
            [
                'workspace_id' => $asset->workspace_id,
                'source' => CaptionSource::Manual,
                'storage_path' => $path,
                'is_default' => $isDefault,
            ],
        );

        if ($isDefault) {
            // One default per asset, or the browser picks arbitrarily.
            MediaCaption::query()
                ->where('media_asset_id', $asset->getKey())
                ->whereKeyNot($caption->getKey())
                ->update(['is_default' => false]);
        }

        return $caption;
    }
}
