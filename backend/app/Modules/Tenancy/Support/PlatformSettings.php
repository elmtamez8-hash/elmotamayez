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
        /*
        | The product's own name (spec 022 follow-up).
        |
        | ⚠️ IT LIVED IN `NEXT_PUBLIC_PLATFORM_NAME` AND THAT IS WHY IT WAS WRONG
        | ON THE LIVE SITE FOR MONTHS. A `NEXT_PUBLIC_*` variable is inlined at
        | BUILD time, so the name could only change by rebuilding — and when it
        | was set nowhere, every title and header quietly read the placeholder
        | «منصّتي» with nothing failing. A row here is read at RUN time, edited
        | from the panel, and falls back to the real name rather than a
        | placeholder.
        |
        | It is the ONE setting in this list a visitor can see, which is why it is
        | also the one with a public endpoint — an allowlist of a single field, so
        | the device limits and the billing knobs beside it stay where they are.
        */
        'platform.name' => 'platform.name',
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
        // The private-session request (spec 023 · FR-022ب). The wait before a
        // request expires itself, and how many one student may leave
        // outstanding with one teacher.
        'sessions.private_request_ttl_hours' => 'sessions.private_request_ttl_hours',
        'sessions.private_request_max_pending' => 'sessions.private_request_max_pending',
        /*
        | ⚠️ ADDED LATE — the fourth instance of this in the map, after the two
        | store keys and the two billing ones. All four are read through
        | `SessionSettings`, which passes the `config/sessions.php` fallback
        | explicitly, so they have always RESOLVED correctly; what a missing key
        | costs is `all()` and `flush()` — invisible to the panel's own listing,
        | and left stale in the cache by a clear. `ManageSessionSettings` writes
        | all four, and a key the panel writes while the map does not know it is
        | the sharper half: the operator changes it and the flush beside the write
        | does not invalidate it.
        */
        'sessions.ticket_ttl_minutes' => 'sessions.ticket_ttl_minutes',
        'sessions.max_participants' => 'sessions.max_participants',
        'sessions.recording_failure_alert_threshold' => 'sessions.recording_failure_alert_threshold',
        'sessions.recording_failure_alert_window_hours' => 'sessions.recording_failure_alert_window_hours',
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
        /*
        | ⚠️ ADDED LATE, EXACTLY AS THE STORE PAIR BELOW WAS — and written by
        | `ManagePlatformSettings` two lines under the comment recording that
        | defect for the store keys. `BillingSettings` reads both through
        | `get()` with the config fallback passed explicitly, so they have
        | always RESOLVED correctly; what a missing key costs is `all()` and
        | `flush()` — invisible to the panel's own listing, and left stale in
        | the cache by a clear. A key the panel WRITES and the map does not
        | know is the sharper half of that: the operator changes it and the
        | flush beside the write does not invalidate it.
        */
        'billing.stop_selling_after_days' => 'billing.stop_selling_after_days',
        'billing.max_unredeemed_credits' => 'billing.max_unredeemed_credits',
        // The family discount (spec 011 · FR-013 · D16). ONE value for the whole
        // platform, applying at every teacher, and taken out of the platform's
        // own commission — «whoever pays is whoever decides», FR-010 to the
        // letter. That is why there is no per-workspace table and no per-teacher
        // row: a teacher who could set it would be setting a discount somebody
        // else funds. A whole percent, 0..100, on the same footing as a coupon's
        // `percent` kind so the two can be compared without a conversion; the
        // default is 0, which is «off» until an operator turns it on.
        'billing.sibling_discount' => 'billing.sibling_discount',
        // Store (spec 011). ⚠️ ADDED LATE: `StoreSettings` reads both of these
        // through `PlatformSettings::get()` passing the config fallback
        // explicitly, so they have always resolved correctly — but a key absent
        // from this map is absent from `all()` and from `flush()`, which means
        // invisible to the panel and un-invalidated by a cache clear. The map is
        // the list of what an operator may change, and these two are on it.
        'store.commission_bps' => 'store.commission_bps',
        'store.refund_window_hours' => 'store.refund_window_hours',
        /*
        | Subscriptions (spec 011 · FR-027 — «مع إبلاغ الطالب قبله بمهلة
        | معلنة»). The notice period is the «معلنة» half: a number an operator
        | changes from the panel, not a constant that can only move by shipping
        | code. Days, and zero switches the notice off entirely.
        */
        // ⚠️ THE VALUE IS THE CONFIG PATH, AND IT READ `subscription.` (singular)
        // AGAINST A FILE NAMED `config/subscriptions.php`. `config()` answers
        // NULL for a path with no file behind it, and `platform_settings.value`
        // is NOT NULL — so `db:seed` DIED HERE on MySQL, third seeder of ten, and
        // the six reference catalogues below it in `DatabaseSeeder` never ran at
        // all. Invisible from the reader's side: `ExpireSubscriptionsJob` passes
        // its own `config('subscriptions.…')` fallback explicitly, so the FEATURE
        // was correct the whole time and only the seeder could see the typo.
        'subscription.expiring_notice_days' => 'subscriptions.expiring_notice_days',
        /*
        | Referrals (spec 011 · FR-023 — «the reward value and its cap must both
        | be adjustable»).
        |
        | ⚠️ THE TWO KEYS ARE READ BY DIFFERENT THINGS, AND ONLY ONE OF THEM IS
        | READ AT RUNTIME. `max_completed_per_referrer` is consulted on every
        | completion — it is the real governor, and lowering it takes effect on
        | the next referral.
        |
        | `reward_points` is read by the BACKFILL MIGRATION ONLY, as the value it
        | seeds the `invite_friend` catalogue row with. After that the CATALOGUE
        | is authoritative and is edited from `/admin` like every other action's
        | xp — because `AwardRequest` deliberately carries no values and
        | `AwardPoints` reads the row. Wiring this key as a second live source
        | would be two answers to one question, which is the drift this file
        | records elsewhere six times over; leaving the number only in a config
        | file would make FR-023 false. This is the seam between the two.
        */
        'referral.reward_points' => 'referral.reward_points',
        'referral.max_completed_per_referrer' => 'referral.max_completed_per_referrer',
        // Publishing new terms is bumping one of these (FR-049). Editable from
        // the panel because that is the whole mechanism: a stored consent names
        // the version it was given for, and the readers ask for the current one.
        'consents.versions.deferred_payment_terms' => 'consents.versions.deferred_payment_terms',
        'consents.versions.data_processing' => 'consents.versions.data_processing',
        // Assessments (spec 008). The sample floor below which an item-analysis
        // rate is withheld rather than stated (FR-013) — editable, because a
        // threshold that only moves with a release never moves.
        'assessments.min_sample_size' => 'assessments.min_sample_size',
        /*
        | The adaptive path (spec 012 · FR-007 — «the thresholds must be
        | adjustable without a deploy»). Four rows, and each one is a judgement
        | about how a real student learns that the first term of real use is what
        | settles.
        |
        | ⚠️ `mastery_correct` IS READ INTO AN `unsignedTinyInt` COLUMN. The
        | setting is a free JSON value, so an operator typing `300` writes a
        | number `concept_masteries.threshold_correct` cannot hold — rejected in
        | strict MySQL, silently truncated in SQLite. `AdaptiveSettings` clamps on
        | the way OUT for that reason; the clamp belongs at the reader because the
        | panel is not the only writer.
        */
        'assessments.adaptive.promote_after' => 'assessments.adaptive.promote_after',
        'assessments.adaptive.mastery_correct' => 'assessments.adaptive.mastery_correct',
        'assessments.adaptive.max_questions' => 'assessments.adaptive.max_questions',
        'assessments.adaptive.start_difficulty' => 'assessments.adaptive.start_difficulty',
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
        /*
        | Analytics (spec 011 · FR-041). How many reviews a teacher must have
        | before the «الأعلى تقييماً» board will rank them at all — the whole of
        | the requirement's «حداً أدنى … يمنع التحيّز», and a number whose right
        | value is whatever the first months of real reviews say it is. A
        | release-only constant here would leave the board rewarding whoever is
        | newest for as long as nobody shipped a change.
        */
        'analytics.min_reviews' => 'analytics.min_reviews',
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
