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
        'media.reconcile_ceiling_hours' => 'media.reconcile_ceiling_hours',
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
        'billing.review_sla_hours' => 'billing.review_sla_hours',
        // Publishing new terms is bumping one of these (FR-049). Editable from
        // the panel because that is the whole mechanism: a stored consent names
        // the version it was given for, and the readers ask for the current one.
        'consents.versions.deferred_payment_terms' => 'consents.versions.deferred_payment_terms',
        'consents.versions.data_processing' => 'consents.versions.data_processing',
        // Assessments (spec 008). The sample floor below which an item-analysis
        // rate is withheld rather than stated (FR-013) — editable, because a
        // threshold that only moves with a release never moves.
        'assessments.min_sample_size' => 'assessments.min_sample_size',
        // Compliance (spec 013). Every deadline and duration the phase enforces
        // is a row here — a legal deadline that only moves with a release is a
        // deadline that is wrong the day the regulator updates its guidance.
        // ⚠️ A KEY ABSENT FROM THIS LIST IS NOT EDITABLE AND NOT READABLE: the
        // map is an explicit allowlist, so a missing entry falls back to config
        // for ever and the panel row does nothing.
        'compliance.request_due_days' => 'compliance.request_due_days',
        'compliance.export_ttl_hours' => 'compliance.export_ttl_hours',
        'compliance.stalled_after_minutes' => 'compliance.stalled_after_minutes',
        'compliance.sweep_lock_minutes' => 'compliance.sweep_lock_minutes',
        'compliance.offboarding_notice_days' => 'compliance.offboarding_notice_days',
        'compliance.breach.authority_notice_hours' => 'compliance.breach.authority_notice_hours',
        'compliance.breach.subject_notice_hours' => 'compliance.breach.subject_notice_hours',
        'compliance.retain_days.min' => 'compliance.retain_days.min',
        'compliance.retain_days.max' => 'compliance.retain_days.max',
        // Community (spec 010). Three rows and no more: the rating gate, the
        // rating period, and the chat send ceiling. The page size and the fan-out
        // chunk beside them in `config/community.php` are engineering constants —
        // moving either changes the shape of a query or a job's timeout budget,
        // not a policy anyone operating the platform has an opinion about.
        'community.review.min_sessions' => 'community.review.min_sessions',
        'community.review.period_days' => 'community.review.period_days',
        'community.chat.max_messages_per_minute' => 'community.chat.max_messages_per_minute',
        // Cohorts (spec 021). Two rows: how many students a new group holds by
        // default, and how long «منع مؤقّت من الكتابة» lasts when the teacher does
        // not say. Both are judgements about one teacher's classroom that the
        // first month of real use is what settles — which is exactly the shape a
        // release-only constant gets wrong for ever.
        'cohorts.default_capacity' => 'cohorts.default_capacity',
        'cohorts.default_chat_ban_minutes' => 'cohorts.default_chat_ban_minutes',
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
