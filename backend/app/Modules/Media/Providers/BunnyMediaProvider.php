<?php

declare(strict_types=1);

namespace App\Modules\Media\Providers;

use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Data\AssetStatusReport;
use App\Modules\Media\Data\PlaybackContext;
use App\Modules\Media\Data\PlaybackManifest;
use App\Modules\Media\Data\ProviderCapabilities;
use App\Modules\Media\Data\Rendition;
use App\Modules\Media\Data\UploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\PlaybackFormat;
use App\Modules\Media\Exceptions\PermanentIngestFailure;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaLimits;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The one file in this module that knows the provider's name (019 FR-019).
 *
 * A thin adapter over three HTTP calls and one signature — no SDK, because there
 * is no official PHP one and a third-party wrapper around three endpoints becomes
 * itself the dependency needing updates and security review, for no gain
 * (research §R2). `ProviderNameContainmentTest` allows the vendor's name here and
 * in `MediaServiceProvider` and nowhere else in `app/`.
 *
 * Three things in here are load-bearing and each is the kind of mistake that
 * ships green:
 *
 * **1 · The title is a join key.** `videos/fetch` does NOT return the new video's
 * id — its 200 is `{success, message, statusCode}` per the OpenAPI schema, and the
 * narrative docs page showing `{id, guid, status}` is the outlier (research §R3).
 * The only field the request accepts and we control is `title`, so the id is
 * recovered by searching for it afterwards. An implementation that reads a `guid`
 * from that response fails SILENTLY: null in `provider_asset_id`, and an asset
 * that reports itself ready and plays nothing.
 *
 * **2 · `token_path` is not a detail.** The playback URL is an HLS manifest, so
 * signing the manifest alone leaves every `.ts` segment open — and whoever holds
 * one URL holds the whole video, with no grant, no watermark and no device limit
 * behind it. It also passes any test that asks for the manifest and stops there
 * (research §R5).
 *
 * **3 · `fetch` creates a new video on EVERY call.** So a retry after a rate limit
 * can produce two videos for one lesson, one of which is paid for monthly and
 * referenced by nothing. The unique title is what makes that visible instead of a
 * bill that grows for no apparent reason.
 */
final class BunnyMediaProvider implements MediaProviderInterface
{
    /** Where the management API lives. Not the CDN, which serves the bytes. */
    private const API = 'https://video.bunnycdn.com';

    /**
     * The provider's own status codes.
     *
     * Only `Finished` is playable. `Created` and `Uploaded` matter for a direct
     * upload; `Processing` and `Transcoding` are what a fetch sits in for minutes.
     */
    private const STATUS_FINISHED = 4;

    private const STATUS_ERROR = 5;

    private const STATUS_UPLOAD_FAILED = 6;

    public function identifier(): string
    {
        return 'bunny';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            // Its own product: every video is transcoded to several ladders.
            adaptiveBitrate: true,
            // Pull-zone token authentication — see manifest() and §R5.
            signedUrls: true,
            /*
             * ⚠️ FALSE BY DECISION, NOT BY LIMITATION. The provider has a complete
             * captions API. We do not use it because a <track> element sends no
             * Authorization header, so a caption URL that the browser can fetch
             * directly is the lesson's entire script on a permanent link. Ours are
             * served through the grant instead (Q5). Declaring true here would
             * have the contract test demand something we deliberately do not do.
             */
            automaticCaptions: false,
            directUpload: true,
            /*
             * From `platform_settings` through MediaLimits, never from a literal:
             * these are the account's own ceilings and an operator raises them
             * without a deploy. Video, because that is what the ladder above
             * describes — a document never reaches this provider.
             */
            maxSizeBytes: MediaLimits::maxSizeBytes(MediaKind::Video),
            maxDurationSeconds: MediaLimits::maxDurationSeconds(MediaKind::Video) ?? 0,
            remoteFetch: true,
        );
    }

    /**
     * A browser-side upload that carries no lasting credential.
     *
     * Two calls, in this order: create the video to learn its id — this endpoint
     * DOES return one, unlike `fetch` — then sign a TUS ticket for it. The
     * signature is a hash OF the key and not the key, so the browser can prove it
     * was authorised for this one video, for the next hour, and nothing else.
     */
    public function createUploadTicket(MediaAsset $asset): UploadTicket
    {
        $videoId = $this->createVideo($this->titleFor($asset));

        // Written now because a direct upload is the one path where the id is
        // known before any bytes move. Nothing to recover later.
        $asset->forceFill(['provider_asset_id' => $videoId])->save();

        $expires = CarbonImmutable::now()->addSeconds((int) config('media.upload_ticket_ttl_seconds'));
        $library = $this->config('library_id');

        return new UploadTicket(
            url: self::API.'/tusupload',
            method: 'POST',
            headers: [],
            fields: [
                // The library id is not a secret — it names which library the
                // signature belongs to, the same way the broadcast provider's API
                // key names which key verifies a ticket. What SIGNS is never sent,
                // and a criterion banning both would be one that cannot pass.
                'LibraryId' => $library,
                'VideoId' => $videoId,
                'AuthorizationExpire' => (string) $expires->getTimestamp(),
                'AuthorizationSignature' => hash(
                    'sha256',
                    $library.$this->config('access_key').$expires->getTimestamp().$videoId,
                ),
            ],
            expiresAt: $expires,
        );
    }

    /**
     * Hand the file over by URL — the whole point of this phase.
     *
     * One call and zero bytes through this worker. The provider does its own GET
     * against a short-lived signed URL, so no lasting link to the recording ever
     * exists (FR-005 · FR-006).
     *
     * ⚠️ NO ID IS LEARNED HERE, and that is the protocol rather than an omission.
     * The asset stays without a `provider_asset_id` until `status()` recovers it by
     * title, which is why an asset can be mid-ingest and neither ready nor failed
     * (SC-014).
     */
    public function ingestFromUrl(MediaAsset $asset, string $sourceUrl, array $sourceHeaders = []): void
    {
        $payload = [
            'url' => $this->fetchableUrl($sourceUrl),
            // The join key. Never a decorative label — see the class docblock.
            'title' => $this->titleFor($asset),
        ];

        /*
         * ⚠️ OMITTED WHEN EMPTY, BECAUSE `[]` IS A JSON ARRAY AND THE SCHEMA WANTS AN
         * OBJECT. `headers` is `{type: object, additionalProperties: {type: string}}`
         * inside a request marked `additionalProperties: false`, and PHP encodes an
         * empty array as `[]`, not `{}`. The production caller passes no headers at
         * all, so this was EVERY delivery — answered 400, which `assertDelivered`
         * classifies as permanent, so the recording failed on its first attempt with
         * no retry and the held fee was released against a lost file. A message
         * about the URL is what the provider would have said, which is why this
         * would have been debugged in the wrong place.
         */
        if ($sourceHeaders !== []) {
            $payload['headers'] = $sourceHeaders;
        }

        $response = $this->request()->post($this->libraryUrl('/videos/fetch'), $payload);

        $this->assertDelivered($response);

        /*
         * ⚠️ THE RESPONSE DOES CARRY AN ID, AND THIS FILE SAID OTHERWISE UNTIL IT WAS
         * RUN AGAINST A REAL ACCOUNT (2026-08-17). The OpenAPI schema types this 200 as
         * `StatusModel` — `{success, message, statusCode}` — and research §R3 built the
         * whole title-as-join-key design on it. The narrative documentation page showed
         * an id and was dismissed as the outlier. The live answer is:
         *
         *     {"id":"42d1c5e3-…","success":true,"message":"OK","statusCode":200}
         *
         * and `GET /videos/{id}` returns that exact value as its `guid`. So the id is
         * authoritative when it arrives.
         *
         * ⚠️ WRITTEN HERE, NOT RETURNED TO THE CALLER, because this is the only window
         * in which it can be lost: a crash between learning the id and saving it leaves
         * a video nothing addresses, which is the same hole the title recovery exists
         * to climb out of. Saved BEFORE anything else can fail.
         *
         * And the title stays load-bearing — the id is the happy path, the title is the
         * recovery path for a response that never arrived (a timeout AFTER Bunny
         * accepted the fetch). Neither replaces the other.
         */
        $id = $response->json('id') ?? $response->json('Id');

        if (is_string($id) && $id !== '') {
            $asset->forceFill(['provider_asset_id' => $id])->save();
        }
    }

    /**
     * What the provider says about this asset, recovering its id first if needed.
     *
     * ⚠️ NEVER THROWS. A provider outage must stop uploads, not break every screen
     * that lists a lesson (FR-016) — so the failure path here is a report.
     *
     * The middle state is the one worth reading twice: a fetch that was accepted
     * but whose video has not appeared yet is reported `Processing`. Not `Ready`,
     * which would publish a lesson pointing at nothing; not `Failed`, which would
     * release the teacher's held fee for a recording still on its way.
     */
    public function status(MediaAsset $asset): AssetStatusReport
    {
        try {
            $videoId = $asset->provider_asset_id ?? $this->recoverVideoId($asset);

            if ($videoId === null) {
                return new AssetStatusReport(status: MediaAssetStatus::Processing);
            }

            $response = $this->request()->get($this->libraryUrl("/videos/{$videoId}"));

            if ($response->status() === 404) {
                return AssetStatusReport::failed('لم يُعثر على الملف عند مزوّد الوسائط.');
            }

            if (! $response->successful()) {
                /*
                 * ⚠️ NOT `failed`, AND THE DIFFERENCE IS A RECORDING.
                 *
                 * `Failed` is terminal here: ReconcileAssetStatus selects
                 * `Processing` only, so one bad five-minute poll — a 500, a 429, a
                 * connect timeout — would declare a healthy video that is still
                 * transcoding dead FOR EVER, and release the teacher's held fee
                 * against a recording nobody can watch. The self-heal this class
                 * promises is unreachable from `Failed`.
                 *
                 * The tell that the old form was a bug and not the contract: the
                 * same outage produced two different verdicts depending only on
                 * whether the id happened to be known yet — a failed title search
                 * returns `Processing` twenty lines above, while a 500 on the direct
                 * GET returned `Failed`. `Failed` is now reserved for the two
                 * answers that mean it: a 404, and a status the provider itself
                 * declares an error.
                 */
                report(new RuntimeException(
                    "مزوّد الوسائط أجاب {$response->status()} عن الأصل {$asset->uuid}."
                ));

                return new AssetStatusReport(status: MediaAssetStatus::Processing);
            }

            return $this->reportFrom($response);
        } catch (Throwable $e) {
            /*
             * Reported rather than swallowed, and reported WITHOUT reaching the
             * asset: `failure_reason` is rendered to the teacher, and a Guzzle
             * message carries the full request URL — the vendor host, the library id
             * and the video id, all three of which FR-019 forbids in a payload. The
             * containment test scans source files, so a runtime-assembled string
             * would walk straight past it.
             */
            report($e);

            return new AssetStatusReport(status: MediaAssetStatus::Processing);
        }
    }

    /**
     * A signed HLS manifest that dies with the grant.
     *
     * ⚠️ SIGNED FOR THE WHOLE DIRECTORY, NOT THE MANIFEST FILE. `token_path` is
     * what makes the token cover `playlist.m3u8` and every `.ts` beside it; the
     * documentation is explicit that path tokens are required for HLS. Without it
     * the index is protected, the segments are wide open, and the test that asks
     * for the index passes (research §R5 · SC-002).
     *
     * ⚠️ AND THE TOKEN GOES IN THE PATH (`/bcdn_token=…`), NOT THE QUERY STRING.
     * Those are two documented forms, not two spellings: a query token is inherited
     * by a relative reference only when that reference has an EMPTY path (RFC 3986),
     * and Bunny's `playlist.m3u8` is a master playlist pointing at `720p/video.m3u8`.
     * So with `?token=…` the index loads signed and every rendition playlist and
     * every `.ts` beneath it leaves unsigned — which is exactly the state SC-002
     * asserts is refused, meaning that guard passed while no student could watch
     * anything. The signature is identical in both forms; only placement differs.
     *
     * ⚠️ AND NO NETWORK CALL. This runs on every playback request, so a call here
     * would turn a provider slowdown into zero viewing rather than degraded
     * viewing (FR-013). The URL is derived and signed locally; that is the entire
     * method.
     *
     * The viewer's IP is deliberately NOT in the signature: a phone moving from
     * wifi to mobile data changes address, and the lesson would cut out mid-way.
     * The device limit answers the same question with a fingerprint that survives
     * a network change.
     */
    public function manifest(PlaybackContext $context): PlaybackManifest
    {
        $expiresAt = CarbonImmutable::instance($context->grant->expires_at->toDateTimeImmutable());
        $videoId = (string) $context->asset->provider_asset_id;

        // Trailing slash: the prefix the token allows is the video's directory, so
        // every segment under it is covered by the one signature.
        $directory = "/{$videoId}/";
        $expires = $expiresAt->getTimestamp();

        // The path form, parameter order as the vendor's reference emits it:
        // bcdn_token, then the signed parameters, then expires, then the real path.
        $url = sprintf(
            'https://%s.b-cdn.net/bcdn_token=%s&token_path=%s&expires=%d%splaylist.m3u8',
            $this->config('pull_zone'),
            $this->token($directory, $expires),
            rawurlencode($directory),
            $expires,
            $directory,
        );

        return new PlaybackManifest(
            format: PlaybackFormat::Hls,
            url: $url,
            // The client never sees this URL: our own route answers 302 with it,
            // which is what keeps the library id and the video id out of every
            // payload we emit (FR-019).
            isRedirect: true,
            expiresAt: $expiresAt,
            // Empty on purpose. The variants live in the master playlist the
            // player already fetches; reading them here would be the network call
            // the paragraph above forbids.
            renditions: [],
        );
    }

    /** Idempotent: a video already gone is a success, and so is one never created. */
    public function delete(MediaAsset $asset): void
    {
        $videoId = $asset->provider_asset_id;

        if ($videoId === null) {
            // ⚠️ NOT "nothing to do, report success". There genuinely is nothing
            // addressable — the id was never recovered — and pretending otherwise
            // is how a paid-for video outlives every reference to it. The caller
            // deleted our row; the reconciliation report is what finds this one.
            return;
        }

        $response = $this->request()->delete($this->libraryUrl("/videos/{$videoId}"));

        // 404 is the second delete, which must be free.
        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('تعذّر حذف الملف عند مزوّد الوسائط.');
        }
    }

    /**
     * The id, found by the one field we chose (research §R4).
     *
     * Polling rather than a webhook, because the retry sweep that asks this
     * question already exists and runs every fifteen minutes — so this costs zero
     * new routes, zero signature verification and zero tables, against a webhook's
     * public endpoint accepting posts from the internet. The price is stated: the
     * id is learned within one sweep, not instantly, and nothing waits on it.
     *
     * ⚠️ MORE THAN ONE MATCH IS NOT A TIE TO BREAK — it is the duplicate that
     * `fetch` creates on retry, and it is reported rather than hidden.
     */
    private function recoverVideoId(MediaAsset $asset): ?string
    {
        $title = $this->titleFor($asset);

        $response = $this->request()->get($this->libraryUrl('/videos'), [
            'search' => $title,
            'itemsPerPage' => 100,
        ]);

        if (! $response->successful()) {
            return null;
        }

        /** @var list<array<string, mixed>> $items */
        $items = $response->json('items') ?? [];

        // Exact match, not "contains": the search is a substring match, and one
        // asset's uuid is never a prefix of another's — but a title_prefix change
        // would otherwise make one asset answer for a differently-named one.
        $matches = array_values(array_filter(
            $items,
            fn (array $item): bool => ($item['title'] ?? null) === $title,
        ));

        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            // Logged, not thrown: the lesson can be published from the first one.
            // What must not happen is nobody ever learning that this account is
            // paying for a video that no lesson points at.
            report(new RuntimeException(sprintf(
                'أكثرُ من ملفٍ بالعنوان نفسه عند مزوّد الوسائط (%d) للأصل %s — تكرارٌ من إعادة محاولة.',
                count($matches),
                $asset->uuid,
            )));
        }

        $videoId = (string) ($matches[0]['guid'] ?? '');

        if ($videoId === '') {
            return null;
        }

        // ⚠️ WRITTEN BEFORE ANYTHING CAN GO READY (FR-006ب). An asset that reaches
        // Ready without this column is playable in name only: nothing can build
        // its URL and nothing can delete it.
        $asset->forceFill(['provider_asset_id' => $videoId])->save();

        return $videoId;
    }

    /** @throws PermanentIngestFailure|RuntimeException */
    private function assertDelivered(Response $response): void
    {
        $body = (array) $response->json();

        // Both casings occur, and reading one misses the other: a success answers
        // lowercase `success`, an authentication refusal answers PascalCase `Success`.
        $message = (string) ($body['message'] ?? $body['Message'] ?? '');
        $declared = $body['success'] ?? $body['Success'] ?? null;

        if ($response->successful() && $declared !== false) {
            return;
        }

        /*
         * ⚠️ THE ORIGIN IS ASKED ABOUT FIRST, AND CLASSIFYING BY STATUS ALONE COSTS A
         * RECORDING. Bunny MIRRORS the source's status code into its own reply, so a
         * source that refuses the fetch produces
         *
         *     403 {"success":false,"message":"Origin returned HTTP 403 (Forbidden).", …}
         *
         * — indistinguishable, by status, from Bunny refusing US. Observed live on
         * 2026-08-17. That mattered because 403 sat in the permanent arm: the very
         * first failure a presigned source URL will ever produce is an expiry, and it
         * would have spent the entire attempt budget at once, marked the recording
         * `failed`, and released the teacher's held fee for a file still sitting intact
         * in the bucket. The retry is what fixes it — the next pass signs a NEW url
         * rather than reusing the one that just lapsed, which is why signing lives in
         * this class and not at the call site.
         *
         * The 422 this used to key on was a reasonable guess and the wrong one; the
         * reasoning it carried is preserved above, the status is not.
         */
        if (str_contains($message, 'Origin returned')) {
            throw new RuntimeException("تعذّر على المزوّد جلبُ الملف من المصدر ({$response->status()}).");
        }

        // Bunny's own refusal of us: a wrong key, or a library that is not ours.
        // Retrying it five times over an hour tells the teacher nothing they could not
        // have been told at once.
        if ($response->status() === 401) {
            throw new PermanentIngestFailure('تهيئةُ مزوّد الوسائط غير صحيحة.');
        }

        // A schema rejection, which carries RFC 9110 problem+json rather than the
        // `success/message` shape. A second identical attempt is malformed too.
        if ($response->status() === 400 && isset($body['errors'])) {
            throw new PermanentIngestFailure('طلبُ التسليم مرفوضٌ من مزوّد الوسائط.');
        }

        /*
         * Everything else retries, deliberately — including 429, whose limit is
         * documented without a number, and including a 200 that declares
         * `success: false`. The budget is the ceiling on optimism here; declaring a
         * failure permanent is the decision that cannot be walked back, so it is made
         * only for the two answers that state their own permanence.
         */
        throw new RuntimeException('تعذّر تسليم الملف إلى مزوّد الوسائط.');
    }

    private function reportFrom(Response $response): AssetStatusReport
    {
        $status = (int) $response->json('status');

        if ($status === self::STATUS_ERROR || $status === self::STATUS_UPLOAD_FAILED) {
            return AssetStatusReport::failed('فشلت معالجةُ الملف عند مزوّد الوسائط.');
        }

        if ($status !== self::STATUS_FINISHED) {
            return new AssetStatusReport(status: MediaAssetStatus::Processing);
        }

        $length = (int) $response->json('length');

        return new AssetStatusReport(
            status: MediaAssetStatus::Ready,
            durationSeconds: $length > 0 ? $length : null,
            // Its own container after transcoding, whatever arrived. Stated rather
            // than probed: the bytes are not here to read, and CompleteMediaUpload
            // treats a null type as a rejection.
            mimeType: 'video/mp4',
            sizeBytes: (int) $response->json('storageSize') ?: null,
            renditions: $this->renditions((string) $response->json('availableResolutions')),
        );
    }

    /**
     * The ladder the provider actually produced, from its own answer.
     *
     * ⚠️ REPORTED HERE AND DELIBERATELY NOT IN `manifest()`. `capabilities()` claims
     * adaptive bitrate and the contract test holds any implementation to what it
     * claims — an empty list here would make that claim decorative, which is the one
     * thing that whole mechanism exists to prevent. `status()` is already an API call
     * and can answer honestly; `manifest()` runs on every playback request and must
     * not call anything, and the player reads the variants out of the master playlist
     * it fetches anyway.
     *
     * @return list<Rendition>
     */
    private function renditions(string $availableResolutions): array
    {
        $renditions = [];

        foreach (explode(',', $availableResolutions) as $resolution) {
            $height = (int) trim($resolution, " p\t\n\r\0\x0B");

            if ($height > 0) {
                $renditions[] = new Rendition(label: $height.'p', height: $height);
            }
        }

        return $renditions;
    }

    private function createVideo(string $title): string
    {
        $response = $this->request()->post($this->libraryUrl('/videos'), ['title' => $title]);

        $this->assertDelivered($response);

        $videoId = (string) ($response->json('guid') ?? '');

        if ($videoId === '') {
            throw new PermanentIngestFailure('لم يُرجع مزوّد الوسائط معرّفاً للملف.');
        }

        return $videoId;
    }

    /**
     * A URL the provider can actually GET.
     *
     * The fetch is the provider's own request and carries none of our credentials,
     * so a private object needs a signed URL. The object key is the last segment
     * of the path because the egress writes flat at the bucket root — the same
     * bucket, by construction: the disk reads the very env vars the egress
     * destination is configured from.
     *
     * An empty `source_disk` means "already fetchable" and the URL passes through,
     * which is what a local run wants.
     */
    private function fetchableUrl(string $sourceUrl): string
    {
        $disk = (string) config('media.bunny.source_disk', '');

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
            CarbonImmutable::now()->addMinutes((int) config('media.bunny.source_url_ttl_minutes')),
        );
    }

    /**
     * `HS256-` + Base64URL(HMAC-SHA256(key, signature_path + expires + signing_data)).
     *
     * The advanced scheme, not the basic one: basic is an MD5 of a concatenation
     * and has no path allowance, so it cannot cover HLS segments at all. No viewer
     * IP by decision (see manifest()), which is also why there is no `1-` flag
     * after the prefix.
     *
     * ⚠️ `token_path` IS PART OF THE HASHED MESSAGE, AND OMITTING IT 403s EVERY
     * VIDEO. It reads like a transport detail and is not: the documentation says
     * every query parameter is signed by default, and the exclusion list is `token`
     * and `expires` alone. This method previously hashed `path + expires` on the
     * reasoning that "we add no query parameters of our own" — but `token_path` is
     * one, so the signature was invalid for every asset, and the suite was green
     * because it asserted the token's SHAPE. The regression guard is now the
     * vendor's own published vector (`BunnyTokenVectorTest`), never another shape
     * assertion: a shape is what a wrong signature also has.
     *
     * `signing_data` is the alphabetically-sorted `key=value` pairs joined by `&`
     * with the RAW (un-encoded) values. We add exactly one parameter, so sorting is
     * a single element — a parameter map here would be an abstraction over one key.
     */
    private function token(string $signaturePath, int $expires): string
    {
        $signingData = 'token_path='.$signaturePath;

        $raw = hash_hmac('sha256', $signaturePath.$expires.$signingData, $this->config('security_key'), true);

        return 'HS256-'.rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function titleFor(MediaAsset $asset): string
    {
        return config('media.bunny.title_prefix').':'.$asset->uuid;
    }

    private function libraryUrl(string $path): string
    {
        return self::API.'/library/'.$this->config('library_id').$path;
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['AccessKey' => $this->config('access_key')])
            ->acceptJson()
            ->asJson()
            // Short: every call here is a small JSON exchange. The one long
            // transfer this provider could make is the one it deliberately does
            // not make.
            ->timeout(30);
    }

    /**
     * A configured value, or a clear refusal.
     *
     * ⚠️ CHECKED HERE RATHER THAN IN THE CONSTRUCTOR, deliberately. The contract
     * test and the container both construct this adapter, and construction must
     * never reach for a credential — the same reason 017's adapter builds its
     * clients lazily. What must fail loudly is a real operation with a missing
     * key, which is this.
     */
    private function config(string $key): string
    {
        $value = (string) config("media.bunny.{$key}", '');

        if ($value === '') {
            throw new PermanentIngestFailure("تهيئةُ مزوّد الوسائط ناقصة: {$key}.");
        }

        return $value;
    }
}
