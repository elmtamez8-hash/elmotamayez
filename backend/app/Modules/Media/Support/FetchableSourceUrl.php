<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Exceptions\PermanentIngestFailure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * A URL that can actually be GET, from a URL that names a private object.
 *
 * ⚠️ THIS USED TO LIVE INSIDE ONE ADAPTER, AND THAT IS WHY NO OTHER PROVIDER
 * COULD INGEST A CLASS RECORDING.
 *
 * `MediaProviderInterface::ingestFromUrl()` documents its `$sourceUrl` as «short-
 * lived and signed, never a public URL» — a precondition on the CALLER. The only
 * caller is `IngestSessionRecordingJob`, and what it passes is
 * the broadcast adapter's plain object URL — the location the egress reports,
 * neither short-lived nor signed. The configured provider compensated
 * privately inside its own adapter, so the violated contract never showed — until
 * a second provider took the same URL and R2 answered
 * `400 InvalidArgument: Authorization`.
 *
 * Measured on 2026-08-26 (`T051` step ٩) by running the ingest with
 * `MEDIA_PROVIDER=local`: the download «succeeded» into a **113-byte** file whose
 * whole content was that XML error. The transfer failing is what stopped it
 * becoming a published lesson — `LocalMediaProvider` writes `provider_asset_id`
 * only on a successful response, deliberately, «because a path recorded for a
 * transfer that failed is an asset that reports Ready and plays nothing».
 *
 * So the knowledge sits in one place that both providers call. It is NOT moved to
 * the caller: the job would then need the storage disk and the bucket layout,
 * which is Media's business and not LiveSessions'.
 *
 * The object key is the last path segment because the egress writes flat at the
 * bucket root — the same bucket by construction, since the `r2` disk reads the
 * very environment variables the egress destination itself is configured from.
 */
final class FetchableSourceUrl
{
    /**
     * An empty `source_disk` means "already fetchable" and the URL passes
     * through, which is what a local run against a public fixture wants.
     */
    public static function for(string $sourceUrl): string
    {
        $disk = (string) config('media.source_disk', '');

        if ($disk === '') {
            return $sourceUrl;
        }

        $path = parse_url($sourceUrl, PHP_URL_PATH);
        $key = is_string($path) ? basename($path) : '';

        if ($key === '') {
            throw new PermanentIngestFailure('تعذّر استخراجُ مسار الملف من رابط المصدر.');
        }

        return Storage::disk($disk)->temporaryUrl(
            $key,
            CarbonImmutable::now()->addMinutes((int) config('media.source_url_ttl_minutes')),
        );
    }
}
