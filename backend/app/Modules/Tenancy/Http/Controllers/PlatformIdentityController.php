<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;

/**
 * What the product calls itself and how to reach it — the platform settings a
 * visitor may read.
 *
 * ⚠️ AN ALLOWLIST, NEVER `PlatformSettings::all()`. That table holds
 * the device limit, the grant TTL, the operating fee and the gateway's basis
 * points; a «settings» endpoint that returned the map would put the platform's
 * half of the price on a public URL, and every key added afterwards would join it
 * silently. `PublicFieldAllowlist` is what fails the build if a field nobody
 * decided on appears here — and the second one earned its place: a support number is
 * published by design, while `billing.transfer` is read by somebody about to pay
 * and stays behind authentication.
 *
 * ⚠️ AND IT IS UNAUTHENTICATED ON PURPOSE. The name is on the login page, in the
 * `<title>` of every public page and in the manifest — it is read before anybody
 * has an account, so requiring one would mean the sign-in screen could not spell
 * the product it signs you in to. The support number is read on the same
 * pages for the same reason: a visitor with no account is exactly who needs to
 * ask a question before making one.
 */
class PlatformIdentityController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => (string) PlatformSettings::get('platform.name'),
                /*
                 * Digits only, or an empty string meaning «no support line».
                 *
                 * ⚠️ THE EMPTY STRING IS THE OFF SWITCH AND IT TRAVELS. A key
                 * left out of the payload when the number is blank would make
                 * the client tell «unset» from «the API is older than this
                 * field» by guessing; an empty string says it in one shape.
                 */
                'support_whatsapp' => (string) PlatformSettings::get('platform.support_whatsapp'),
                // The legal pages' identity. Empty strings, never absent keys,
                // for the reason given above.
                'legal_name' => (string) PlatformSettings::get('platform.legal_name'),
                'postal_address' => (string) PlatformSettings::get('platform.postal_address'),
                'contact_email' => (string) PlatformSettings::get('platform.contact_email'),
                /*
                 * The two freeze limits /terms states (owner decision
                 * 2026-09-27). Integers, clamped to at least one exactly as
                 * `SessionSettings::freezeMaxDays()` / `freezeMaxPerMonth()`
                 * clamp them — `PlatformIdentityTest` pins the two readers
                 * together, because a page that promised a different number
                 * from the one `CreateFreezePeriod` refuses at is a clause the
                 * product breaks.
                 *
                 * ⚠️ READ HERE RATHER THAN THROUGH `SessionSettings`, because
                 * LiveSessions already depends on Tenancy for these very rows;
                 * the reverse import would make the two modules a cycle.
                 */
                'freeze_max_days' => max(1, (int) PlatformSettings::get('sessions.freeze_max_days')),
                'freeze_max_per_month' => max(1, (int) PlatformSettings::get('sessions.freeze_max_per_month')),
                ...$this->termsNumbers(),
            ],
        ]);
    }

    /**
     * Every other operational number /terms and /refunds state (owner decision
     * 2026-09-27, the pre-launch audit). Same reason as the freeze limits above:
     * the pages spelled «٢٤ حصة», «٦٠ يوماً», «٢٤ ساعة»… in their text while each is
     * a `platform_settings` row an operator edits from the panel — so the day one
     * moved, the page promised a number the Action no longer honoured.
     *
     * ⚠️ EACH LINE REPEATS ITS ACCESSOR'S DEFAULT AND CLAMP, CHARACTER FOR
     * CHARACTER, and `PlatformIdentityTest` compares every field against the
     * accessor itself (`BillingSettings`, `SessionSettings`, `CommunitySettings`,
     * `ComplianceSettings`, `StoreSettings`, `TwoFactorMandate`) before and after
     * an operator edit. Read here rather than through those classes for the
     * reason the freeze limits give: they live in modules that depend on this one.
     *
     * @return array<string, int>
     */
    private function termsNumbers(): array
    {
        return [
            // TwoFactorMandate::applyTo() — clamped at the point of use.
            'two_factor_grace_days' => max(0, (int) PlatformSettings::get('auth.two_factor_grace_days', 14)),
            // BillingSettings — the escrow guards and the dormancy notice.
            'max_unredeemed_credits' => max(1, (int) PlatformSettings::get('billing.max_unredeemed_credits', config('billing.max_unredeemed_credits', 24))),
            'stop_selling_after_days' => max(1, (int) PlatformSettings::get('billing.stop_selling_after_days', config('billing.stop_selling_after_days', 60))),
            'dormant_notice_months' => max(1, (int) PlatformSettings::get('billing.dormant_notice_months', 12)),
            'receipt_review_sla_hours' => max(1, (int) PlatformSettings::get('billing.review_sla_hours', config('billing.review_sla_hours', 24))),
            // BillingSettings — the deferred-payment (credit-limit) ladder.
            'deferred_initial_credits' => max(0, (int) PlatformSettings::get('billing.limit.initial_credits', 1)),
            'deferred_increase_after_on_time' => max(1, (int) PlatformSettings::get('billing.limit.increase_after_on_time', 3)),
            'deferred_increase_by_credits' => max(0, (int) PlatformSettings::get('billing.limit.increase_by_credits', 1)),
            'deferred_max_credits' => max(0, (int) PlatformSettings::get('billing.limit.max_credits', 4)),
            'deferred_reset_after_late_days' => max(1, (int) PlatformSettings::get('billing.limit.decrease_after_late_days', 14)),
            // SessionSettings — cancellation, the attendance bar, corrections.
            'cancellation_window_minutes' => (int) PlatformSettings::get('sessions.cancellation_window_minutes', 1440),
            // The share of the session a student must stay to count as having
            // attended (`requiredStaySeconds()` — the bar `CloseClassSession`
            // charges against), as a whole percentage.
            'attendance_required_stay_percent' => (int) round((float) PlatformSettings::get('sessions.required_stay_ratio', 0.5) * 100),
            'attendance_edit_window_hours' => (int) PlatformSettings::get('sessions.attendance_edit_window_hours', 48),
            // ExpireSubscriptionsJob::warn() — below one, no reminder is sent.
            'renewal_notice_days' => (int) PlatformSettings::get('subscription.expiring_notice_days', (int) config('subscriptions.expiring_notice_days', 3)),
            // CommunitySettings — the chat limiter and the review door.
            'chat_max_messages_per_minute' => (int) PlatformSettings::get('community.chat.max_messages_per_minute', 30),
            'review_min_sessions' => (int) PlatformSettings::get('community.review.min_sessions', 4),
            'review_period_days' => (int) PlatformSettings::get('community.review.period_days', 30),
            // ComplianceSettings — a departing teacher's notice period.
            'offboarding_notice_days' => (int) PlatformSettings::get('compliance.offboarding_notice_days', 30),
            // StoreSettings — the store's self-service refund window.
            'store_refund_window_hours' => max(0, (int) PlatformSettings::get('store.refund_window_hours', config('store.refund_window_hours', 48))),
        ];
    }
}
