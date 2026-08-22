<?php

declare(strict_types=1);

namespace App\Modules\Community\Support;

use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * Every threshold this phase enforces, read from `platform_settings` and falling
 * back to `config/community.php`.
 *
 * ⚠️ NOT ONE CONSTANT IN THE CODE, per the repository rule written in
 * `config/media.php`: operational numbers are rows an operator edits from the
 * panel, and one that can only change by shipping code is one nobody ever tunes.
 *
 * ⚠️ AND THE SECRETS EXCEPTION DOES NOT APPLY — nothing here is a secret. Spec
 * 010's one secret is `REVERB_APP_SECRET`, which lives in the environment for the
 * reason 019 wrote down: a `platform_settings` row is readable by everyone who can
 * open the panel, so a signing key there widens who can forge a subscription from
 * "whoever administers the server" to "whoever administers a workspace".
 */
final class CommunitySettings
{
    /**
     * How many sessions counted as attended a student needs before they may rate
     * their teacher (FR-030).
     */
    public static function reviewMinSessions(): int
    {
        return (int) PlatformSettings::get('community.review.min_sessions', 4);
    }

    /** How long one rating period lasts, in days (FR-032). */
    public static function reviewPeriodDays(): int
    {
        return (int) PlatformSettings::get('community.review.period_days', 30);
    }

    /**
     * The per-user send ceiling behind the named `chat-write` limiter.
     *
     * ⚠️ READ THROUGH `PlatformSettings`, WHICH IS A CACHE HIT AND NOT A QUERY —
     * `rememberForever` — because this one is consulted on every write request.
     */
    public static function maxMessagesPerMinute(): int
    {
        return (int) PlatformSettings::get('community.chat.max_messages_per_minute', 30);
    }

    /**
     * Messages per page.
     *
     * From config alone, never `platform_settings`: keyset pagination sizes are
     * an engineering constant, the same call `ComplianceSettings::batchSize()`
     * makes.
     */
    public static function messagePageSize(): int
    {
        return (int) config('community.chat.page_size', 50);
    }

    /** Recipients per announcement fan-out batch. Config alone, as above. */
    public static function fanOutChunk(): int
    {
        return (int) config('community.announcements.fanout_chunk', 100);
    }
}
