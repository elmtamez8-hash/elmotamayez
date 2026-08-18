<?php

declare(strict_types=1);

/*
| Defaults only. Every value here can be overridden at runtime by a row in
| `platform_settings`, which an operator edits from the panel without a deploy —
| see App\Modules\Tenancy\Support\PlatformSettings. This file is what the system
| falls back to on a database with no settings seeded, so it must be a working
| configuration on its own.
*/

return [

    /*
    | The video provider. `local` needs no external account and no network: it
    | stores on the private disk and streams by range request. A commercial
    | provider is a second file in Modules/Media/Providers plus a case here.
    */
    'provider' => env('MEDIA_PROVIDER', 'local'),

    /*
    | Disk for the local provider. Must be private — a video on the public disk
    | is a permanent shareable link, which is the thing this module exists to
    | remove.
    */
    'disk' => env('MEDIA_DISK', 'local'),

    /*
    | The commercial provider's account (spec 019).
    |
    | ⚠️ EVERY SECRET HERE COMES FROM THE ENVIRONMENT AND NOWHERE ELSE, and that
    | is a departure from this file's own rule that operational numbers live in
    | `platform_settings`. The rule is about NUMBERS — the device limit, the upload
    | ceiling, the grant TTL — which an operator tunes from the panel. A row in
    | `platform_settings` is readable by everyone who can open that panel, so
    | putting the signing key there widens who can mint a valid playback token
    | from "whoever administers the server" to "whoever administers a workspace",
    | for a key whose leak opens the whole library with no trace in our logs
    | (019 FR-020أ · research §R9).
    |
    | The LIMITS are the other half of that split and they are NOT here: they come
    | from `platform_settings` through MediaLimits, which already reads them.
    */
    'bunny' => [
        // The video library. Also the AccessKey's scope: the key opens this
        // library and no other.
        'library_id' => env('BUNNY_STREAM_LIBRARY_ID'),
        'access_key' => env('BUNNY_STREAM_ACCESS_KEY'),

        // The CDN hostname the player pulls segments from, and the key that
        // signs those URLs. Two different credentials with two different jobs:
        // the access key manages videos, the security key authorises watching one.
        'pull_zone' => env('BUNNY_PULL_ZONE'),
        'security_key' => env('BUNNY_PULL_ZONE_SECURITY_KEY'),

        /*
        | ⚠️ THE JOIN KEY, NOT A LABEL.
        |
        | `videos/fetch` answers `{success, message, statusCode}` — it does NOT
        | return the new video's guid (research §R3, from the OpenAPI schema; the
        | narrative docs page disagrees and is the outlier). The only field the
        | request accepts and we control is `title`, so the title is how the video
        | is found again afterwards. Changing this prefix orphans every asset
        | whose id has not been recovered yet.
        */
        'title_prefix' => env('BUNNY_TITLE_PREFIX', 'mteatch'),

        /*
        | Where the provider fetches FROM. Empty means "the URL handed to
        | ingestFromUrl is already fetchable" — which is what a local development
        | run wants. Set to the bridge disk in production: the object is private,
        | and Bunny's fetch is its own GET carrying none of our credentials.
        */
        'source_disk' => env('BUNNY_SOURCE_DISK', 'r2'),

        // Long enough for a fetch of a two-hour lesson to start and finish, short
        // enough that a leaked source URL is not a lasting one.
        'source_url_ttl_minutes' => (int) env('BUNNY_SOURCE_URL_TTL_MINUTES', 120),
    ],

    // Upload limits (FR-003). Enforced in the Action, not only in validation.
    'max_size_bytes' => (int) env('MEDIA_MAX_SIZE_BYTES', 2_147_483_648),   // 2 GiB
    'max_duration_seconds' => (int) env('MEDIA_MAX_DURATION_SECONDS', 14_400), // 4 h

    /*
    | Per kind since 016. It was one flat list while video was the only thing
    | that could be uploaded — and `pdf` and `file` had been declared lesson
    | types since the first migration with no way to author either. Keeping one
    | list would mean a PDF is accepted by the same rule as a 2 GiB video, or
    | that documents stay unauthorable. The keys match MediaKind.
    */
    'allowed_mime_types' => [
        'video' => [
            'video/mp4',
            'video/webm',
            'video/quicktime',
            'video/x-matroska',
        ],
        'audio' => [
            'audio/mpeg',
            'audio/mp4',
            'audio/aac',
            'audio/ogg',
            'audio/wav',
            'audio/x-wav',
        ],
        'document' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'image/png',
            'image/jpeg',
        ],
    ],

    /*
    | Size ceilings per kind. A lecture note is not a lecture: giving a PDF the
    | video allowance means one mis-picked file uploads a gigabyte before anyone
    | notices. Overridden at runtime by platform_settings, like every other
    | operational number here.
    */
    'max_document_size_bytes' => (int) env('MEDIA_MAX_DOCUMENT_SIZE_BYTES', 52_428_800),  // 50 MiB
    'max_audio_size_bytes' => (int) env('MEDIA_MAX_AUDIO_SIZE_BYTES', 209_715_200),       // 200 MiB
    'max_audio_duration_seconds' => (int) env('MEDIA_MAX_AUDIO_DURATION_SECONDS', 14_400), // 4 h

    /*
    | Playback grants (FR-008, FR-012). The TTL is short because the watermark
    | renews it every 60s; a viewer who stops renewing loses playback within one
    | TTL. max_renewals bounds a single viewing session so a grant cannot be kept
    | alive indefinitely.
    */
    'grant_ttl_seconds' => (int) env('MEDIA_GRANT_TTL_SECONDS', 300),
    'grant_renew_interval_seconds' => (int) env('MEDIA_GRANT_RENEW_INTERVAL', 60),
    'max_renewals' => (int) env('MEDIA_MAX_RENEWALS', 480),  // ~8 h of viewing

    /*
    | How long ReconcileAssetStatus keeps asking about one asset.
    |
    | ⚠️ A CEILING AND A VERDICT, NOT JUST A CEILING. The sweep runs every five
    | minutes and spends up to two provider calls per `Processing` asset, for ever
    | — an asset whose delivery never landed is interrogated hundreds of times a
    | day until the end of the deployment. But stopping the questions alone would
    | leave that asset «قيد التجهيز» permanently, which is the exact state this
    | job was scheduled to abolish. Past the ceiling it is written failed WITH a
    | reason, and a manual upload is the way out (FR-031).
    |
    | Two days, matching the recording sweep's own window: no transcode takes
    | that long, and the ingest budget has given up many hours earlier.
    */
    'reconcile_ceiling_hours' => (int) env('MEDIA_RECONCILE_CEILING_HOURS', 48),

    // How long an upload ticket stays valid.
    'upload_ticket_ttl_seconds' => (int) env('MEDIA_UPLOAD_TICKET_TTL', 3600),

    /*
    | Active DEVICES per platform role, not sessions: two sessions on one laptop
    | are one device and must not evict each other (FR-022ب). A role that is not
    | listed has no limit — a teacher with a panel, a laptop and a phone is doing
    | legitimate work, and a limit there obstructs it rather than protecting it.
    */
    'device_limits' => [
        'student' => 1,
    ],

    /*
    | A forced logout at a limit of one device happens on every ordinary move
    | between phone and laptop. An alert that arrives daily is one the user
    | learns to dismiss — so it is sent only when the evicted session was active
    | within this window (FR-025).
    */
    'device_alert_active_within_minutes' => (int) env('MEDIA_DEVICE_ALERT_WINDOW', 30),

    // Grace period before an admin account is restricted for not enabling 2FA (FR-028).
    'two_factor_grace_days' => (int) env('TWO_FACTOR_GRACE_DAYS', 14),
];
