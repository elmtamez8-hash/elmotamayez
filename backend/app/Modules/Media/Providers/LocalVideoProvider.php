<?php

declare(strict_types=1);

namespace App\Modules\Media\Providers;

use App\Modules\Media\Contracts\VideoProviderInterface;
use App\Modules\Media\Data\AssetStatusReport;
use App\Modules\Media\Data\PlaybackContext;
use App\Modules\Media\Data\PlaybackManifest;
use App\Modules\Media\Data\ProviderCapabilities;
use App\Modules\Media\Data\UploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\PlaybackFormat;
use App\Modules\Media\Models\MediaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Stores on the private disk and streams by byte range. No account, no network.
 *
 * This exists so the commercial provider choice can be made later without the
 * code waiting on it. It is not a stand-in that "sort of" works: the range
 * serving is the reason a grant can expire mid-file, which is what proves the
 * watermark guard locally instead of only on paper.
 *
 * What it does not do is transcode. Adaptive bitrate is declared false, and the
 * contract test only holds an implementation to what it claims.
 */
class LocalVideoProvider implements VideoProviderInterface
{
    public function identifier(): string
    {
        return 'local';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            // Would mean transcoding to several streams — half a streaming
            // provider, thrown away the day a real one is signed.
            adaptiveBitrate: false,
            signedUrls: false,
            automaticCaptions: false,
            // No external host to upload to, so the ticket points back at us.
            directUpload: false,
            maxSizeBytes: (int) config('media.max_size_bytes'),
            maxDurationSeconds: (int) config('media.max_duration_seconds'),
        );
    }

    public function createUploadTicket(MediaAsset $asset): UploadTicket
    {
        $ttl = (int) config('media.upload_ticket_ttl_seconds');

        return new UploadTicket(
            // The asset's own uuid is the token: it is unguessable, it already
            // scopes the upload to one asset, and a second secret would need its
            // own expiry and its own storage for no extra safety.
            url: url("/api/v1/media/upload/{$asset->uuid}"),
            method: 'PUT',
            headers: ['Content-Type' => 'application/octet-stream'],
            fields: [],
            expiresAt: CarbonImmutable::now()->addSeconds($ttl),
        );
    }

    public function status(MediaAsset $asset): AssetStatusReport
    {
        $path = $asset->provider_asset_id;

        if ($path === null) {
            return AssetStatusReport::failed('لم يصل الملف بعد.');
        }

        try {
            $disk = $this->disk();

            if (! $disk->exists($path)) {
                return AssetStatusReport::failed('تعذّر العثور على الملف المرفوع.');
            }

            return new AssetStatusReport(
                // No processing step: the file is playable as soon as it lands.
                status: MediaAssetStatus::Ready,
                durationSeconds: $asset->duration_seconds,
                mimeType: $this->detectMimeType($path),
                sizeBytes: $disk->size($path),
            );
        } catch (Throwable $e) {
            // A provider that is down must degrade uploading, not break every
            // screen that lists a lesson.
            return AssetStatusReport::failed('تعذّر الوصول إلى مزوّد الفيديو: '.$e->getMessage());
        }
    }

    public function manifest(PlaybackContext $context): PlaybackManifest
    {
        return new PlaybackManifest(
            format: PlaybackFormat::Progressive,
            url: url("/api/v1/playback/{$context->grant->uuid}/stream"),
            // We serve the bytes ourselves. A commercial provider flips this to
            // true and the same route becomes a 302 to its signed URL.
            isRedirect: false,
            expiresAt: CarbonImmutable::instance($context->grant->expires_at->toDateTimeImmutable()),
            renditions: [],
        );
    }

    public function delete(MediaAsset $asset): void
    {
        $path = $asset->provider_asset_id;

        if ($path === null) {
            return;
        }

        // Idempotent: deleting what is already gone is a success, not an error.
        $this->disk()->delete($path);
    }

    /**
     * Where the local provider keeps a stored asset.
     */
    public function pathFor(MediaAsset $asset): string
    {
        return "media/{$asset->uuid}";
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('media.disk'));
    }

    /**
     * Read the type out of the file's own bytes.
     *
     * Not Filesystem::mimeType(), which leans on the extension: the uploader
     * chooses the filename, so a zip called lesson.mp4 would be reported as video
     * and published as one. Magic bytes are the only signal here the client does
     * not control.
     *
     * Null when nothing recognisable is found — which CompleteMediaUpload treats
     * as a rejection, not as permission.
     */
    private function detectMimeType(string $path): ?string
    {
        $stream = $this->disk()->readStream($path);

        if ($stream === null) {
            return null;
        }

        $head = fread($stream, 8192);
        fclose($stream);

        if ($head === false || $head === '') {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_buffer($finfo, $head);
        finfo_close($finfo);

        return $mime === false || $mime === '' ? null : $mime;
    }
}
