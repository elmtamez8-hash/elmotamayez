<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Support;

use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * Every deadline and duration this phase enforces, read from `platform_settings`
 * and falling back to `config/compliance.php`.
 *
 * ⚠️ NOT ONE CONSTANT IN THE CODE. The repository rule is written in
 * `config/media.php`: operational numbers are rows an operator edits from the
 * panel, and one that can only change by shipping code is one nobody tunes. It
 * bites harder here than anywhere else — `request_due_days` is a LEGAL deadline,
 * and the day a regulator shortens it, a constant makes the platform
 * non-compliant until the next release.
 *
 * ⚠️ AND THE SECRETS EXCEPTION DOES NOT APPLY. That rule (019) keeps signing keys
 * in the environment because a `platform_settings` row is readable by everyone who
 * can open the panel. Nothing here is a secret; these are all numbers.
 */
final class ComplianceSettings
{
    public static function requestDueDays(): int
    {
        return (int) PlatformSettings::get('compliance.request_due_days', 30);
    }

    public static function exportTtlHours(): int
    {
        return (int) PlatformSettings::get('compliance.export_ttl_hours', 48);
    }

    public static function stalledAfterMinutes(): int
    {
        return (int) PlatformSettings::get('compliance.stalled_after_minutes', 30);
    }

    public static function sweepLockMinutes(): int
    {
        return (int) PlatformSettings::get('compliance.sweep_lock_minutes', 180);
    }

    public static function offboardingNoticeDays(): int
    {
        return (int) PlatformSettings::get('compliance.offboarding_notice_days', 30);
    }

    public static function authorityNoticeHours(): int
    {
        return (int) PlatformSettings::get('compliance.breach.authority_notice_hours', 72);
    }

    public static function subjectNoticeHours(): int
    {
        return (int) PlatformSettings::get('compliance.breach.subject_notice_hours', 72);
    }

    /**
     * The floor a category's retention may not go below.
     *
     * ⚠️ ZERO IS NOT "IMMEDIATELY", it is deleting the platform's data tonight —
     * and it is one keystroke away from a legitimate value in a numeric field.
     */
    public static function minRetainDays(): int
    {
        return (int) PlatformSettings::get('compliance.retain_days.min', 1);
    }

    /**
     * The ceiling, which is a COLUMN WIDTH rather than a preference.
     *
     * `unsignedSmallInteger` stops at 65,535; past it, `created_at + n days` runs
     * off the end of the calendar. MySQL raises ERROR 1441 and kills the whole
     * sweep; SQLite returns NULL so nothing ever expires. Neither is visible on a
     * development machine.
     */
    public static function maxRetainDays(): int
    {
        return (int) PlatformSettings::get('compliance.retain_days.max', 65535);
    }

    public static function batchSize(string $kind): int
    {
        /** @var array<string, int> $sizes */
        $sizes = (array) config('compliance.batch', []);

        return (int) ($sizes[$kind] ?? 500);
    }

    public static function exportDisk(): string
    {
        return (string) config('compliance.export_disk', 'local');
    }
}
