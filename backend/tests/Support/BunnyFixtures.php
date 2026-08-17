<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The configuration a Bunny test needs, in one place.
 *
 * A class rather than a Pest helper function on purpose: a function declared in
 * one test file only exists once Pest has loaded that file, so a sibling file
 * borrowing it passes or fails depending on execution order.
 *
 * ⚠️ THE `r2` DISK HERE CARRIES REAL-SHAPED S3 CREDENTIALS AND THAT IS
 * DELIBERATE. `Storage::fake()` cannot presign, so faking it would skip the
 * signing path entirely — and signing the source URL is half of what this phase
 * does. The S3 adapter presigns locally with zero network traffic, which is also
 * why the zero-bandwidth guard stays honest: the signed host appears in a URL and
 * never in a request.
 */
final class BunnyFixtures
{
    /** The host of the presigned source URL. Nothing may ever request it. */
    public const SOURCE_HOST = 'test-account.r2.cloudflarestorage.com';

    public const LIBRARY_ID = '99001';

    public const PULL_ZONE = 'mteatch-test';

    public const TITLE_PREFIX = 'mteatch';

    /** @return array<string, mixed> */
    public static function config(): array
    {
        return [
            'media.provider' => 'bunny',
            'media.bunny.library_id' => self::LIBRARY_ID,
            'media.bunny.access_key' => 'test-access-key',
            'media.bunny.pull_zone' => self::PULL_ZONE,
            'media.bunny.security_key' => 'test-security-key',
            'media.bunny.title_prefix' => self::TITLE_PREFIX,
            'media.bunny.source_disk' => 'r2',
            'media.bunny.source_url_ttl_minutes' => 120,
            'filesystems.disks.r2' => [
                'driver' => 's3',
                'key' => 'test-r2-key',
                'secret' => 'test-r2-secret',
                'region' => 'auto',
                'bucket' => 'recordings',
                'endpoint' => 'https://'.self::SOURCE_HOST,
                'use_path_style_endpoint' => true,
                'throw' => false,
                'report' => false,
            ],
        ];
    }

    /** The management API base for the configured library. */
    public static function api(string $path = ''): string
    {
        return 'https://video.bunnycdn.com/library/'.self::LIBRARY_ID.$path;
    }

    /**
     * What `videos/fetch` actually answers.
     *
     * ⚠️ NO `guid`, and a test that adds one is testing a protocol the provider
     * does not speak: the 200 is `StatusModel` per the OpenAPI schema, and the
     * narrative docs page showing an id is the outlier (research §R3).
     *
     * @return array<string, mixed>
     */
    public static function fetchAccepted(): array
    {
        return ['success' => true, 'message' => 'Video fetched', 'statusCode' => 200];
    }

    /**
     * A video row as the API returns it.
     *
     * @return array<string, mixed>
     */
    public static function video(string $guid, string $title, int $status = 4, int $length = 3600): array
    {
        return [
            'guid' => $guid,
            'title' => $title,
            // 4 is Finished — the only playable one. 2/3 are processing and
            // transcoding, 5/6 are the two ways it can fail.
            'status' => $status,
            'length' => $length,
            'storageSize' => 104_857_600,
            // The ladder the provider produced. `capabilities()` claims adaptive
            // bitrate, and the contract test holds it to that claim — so a fixture
            // with one resolution would be testing a dishonest provider.
            'availableResolutions' => '360p,720p,1080p',
        ];
    }

    /** @return array<string, mixed> */
    public static function searchResult(array ...$videos): array
    {
        return ['items' => array_values($videos), 'totalItems' => count($videos)];
    }

    public static function titleFor(string $assetUuid): string
    {
        return self::TITLE_PREFIX.':'.$assetUuid;
    }
}
