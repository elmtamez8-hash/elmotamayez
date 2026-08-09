<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Operational values an administrator can change without a deploy.
 *
 * Why a table and not config(): FR-022 asks for an *adjustable* device limit,
 * and a constant in a config file is adjustable only by shipping code. A limit
 * that can only be changed by a deploy is a limit nobody ever tunes.
 *
 * config() stays as the fallback, so the application runs correctly against a
 * database with nothing seeded — a settings row is an override, never a
 * requirement.
 */
final class PlatformSettings
{
    private const CACHE_PREFIX = 'platform_settings:';

    /**
     * Map of setting key => config key it falls back to.
     *
     * Explicit rather than derived: it is the list of what an operator is
     * allowed to change, and deriving it from config() would expose every
     * framework value in the panel.
     *
     * @var array<string, string>
     */
    public const KEYS = [
        'auth.device_limits' => 'media.device_limits',
        'auth.two_factor_grace_days' => 'media.two_factor_grace_days',
        'media.max_size_bytes' => 'media.max_size_bytes',
        'media.max_duration_seconds' => 'media.max_duration_seconds',
        'media.max_document_size_bytes' => 'media.max_document_size_bytes',
        'media.max_audio_size_bytes' => 'media.max_audio_size_bytes',
        'media.max_audio_duration_seconds' => 'media.max_audio_duration_seconds',
        'media.grant_ttl_seconds' => 'media.grant_ttl_seconds',
        'media.max_renewals' => 'media.max_renewals',
        'sessions.timezone' => 'sessions.timezone',
        'sessions.grace_minutes' => 'sessions.grace_minutes',
        'sessions.absence_threshold_ratio' => 'sessions.absence_threshold_ratio',
        'sessions.required_stay_ratio' => 'sessions.required_stay_ratio',
        'sessions.teacher_required_stay_ratio' => 'sessions.teacher_required_stay_ratio',
        'sessions.cancellation_window_minutes' => 'sessions.cancellation_window_minutes',
        'sessions.join_window_minutes' => 'sessions.join_window_minutes',
        'sessions.presence_interval_seconds' => 'sessions.presence_interval_seconds',
        'sessions.attendance_edit_window_hours' => 'sessions.attendance_edit_window_hours',
        'sessions.report_delay_minutes' => 'sessions.report_delay_minutes',
        'settlement.period_days' => 'settlement.period_days',
        'settlement.required_package_components' => 'settlement.required_package_components',
        'settlement.zero_attendance_compensation_enabled' => 'settlement.zero_attendance_compensation_enabled',
        'settlement.zero_attendance_compensation_percent' => 'settlement.zero_attendance_compensation_percent',
        'settlement.rate_requests_per_window' => 'settlement.rate_requests_per_window',
        'settlement.rate_request_window_days' => 'settlement.rate_request_window_days',
        'settlement.currency' => 'settlement.currency',
        // Billing (spec 006). The platform's half of the price and of the credit
        // policy; the workspace's half lives in `workspaces.settings.billing`.
        'billing.operating_fee_minor.individual' => 'billing.operating_fee_minor.individual',
        'billing.operating_fee_minor.group' => 'billing.operating_fee_minor.group',
        'billing.gateway_fee_bps' => 'billing.gateway_fee_bps',
        'billing.gateway_fixed_fee_minor' => 'billing.gateway_fixed_fee_minor',
        'billing.currency' => 'billing.currency',
        'billing.limit.initial_credits' => 'billing.limit.initial_credits',
        'billing.limit.increase_after_on_time' => 'billing.limit.increase_after_on_time',
        'billing.limit.increase_by_credits' => 'billing.limit.increase_by_credits',
        'billing.limit.max_credits' => 'billing.limit.max_credits',
        'billing.limit.decrease_after_late_days' => 'billing.limit.decrease_after_late_days',
        'billing.dormant_notice_months' => 'billing.dormant_notice_months',
        'billing.max_lots_per_draw' => 'billing.max_lots_per_draw',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $stored = Cache::rememberForever(
            self::CACHE_PREFIX.$key,
            // Wrapped in an array so a legitimately-null stored value is not
            // re-queried on every read as though it were a cache miss.
            fn (): array => ['value' => PlatformSetting::query()->find($key)?->value],
        );

        if ($stored['value'] !== null) {
            return $stored['value'];
        }

        $configKey = self::KEYS[$key] ?? null;

        return $configKey === null ? $default : config($configKey, $default);
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by_user_id' => $userId],
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /**
     * Every editable setting with its effective value.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $values = [];

        foreach (array_keys(self::KEYS) as $key) {
            $values[$key] = self::get($key);
        }

        return $values;
    }

    public static function flush(): void
    {
        foreach (array_keys(self::KEYS) as $key) {
            Cache::forget(self::CACHE_PREFIX.$key);
        }
    }
}
