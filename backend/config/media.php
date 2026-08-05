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

    // Upload limits (FR-003). Enforced in the Action, not only in validation.
    'max_size_bytes' => (int) env('MEDIA_MAX_SIZE_BYTES', 2_147_483_648),   // 2 GiB
    'max_duration_seconds' => (int) env('MEDIA_MAX_DURATION_SECONDS', 14_400), // 4 h

    'allowed_mime_types' => [
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'video/x-matroska',
    ],

    /*
    | Playback grants (FR-008, FR-012). The TTL is short because the watermark
    | renews it every 60s; a viewer who stops renewing loses playback within one
    | TTL. max_renewals bounds a single viewing session so a grant cannot be kept
    | alive indefinitely.
    */
    'grant_ttl_seconds' => (int) env('MEDIA_GRANT_TTL_SECONDS', 300),
    'grant_renew_interval_seconds' => (int) env('MEDIA_GRANT_RENEW_INTERVAL', 60),
    'max_renewals' => (int) env('MEDIA_MAX_RENEWALS', 480),  // ~8 h of viewing

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
