<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\BillingCadence;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\ZeroBalanceBehavior;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * The one place the billing decision is made (FR-013).
 *
 * Two halves, deliberately kept apart:
 *
 *   - the WORKSPACE's half — mode, alert thresholds, zero-balance behaviour —
 *     read from `workspaces.settings.billing`, because they differ per teacher;
 *   - the PLATFORM's half — the operating fee, gateway fees, the credit-limit
 *     policy — read from `platform_settings`, because they are the platform's
 *     price and FR-021ب forbids a teacher setting them.
 *
 * The split is forced, not stylistic: PlatformSettings is platform-wide by
 * construction — one key, one row, no `workspace_id` — so putting a per-workspace
 * mode in it would contradict FR-011 itself.
 *
 * ⚠️ The workspace is always PASSED IN, never resolved from WorkspaceContext.
 * The busiest caller is a queued listener, where the context is null and the
 * ambient answer would be the default mode for every teacher on the platform.
 */
class BillingSettings
{
    private const SETTINGS_KEY = 'billing';

    // The workspace half ------------------------------------------------------

    /**
     * How this workspace collects.
     *
     * The default is named here and nowhere else. An unrecognised stored value
     * falls back rather than throwing: a mode retired by a later release would
     * otherwise take down every booking in a workspace that still had it saved.
     */
    public function mode(Workspace $workspace): BillingMode
    {
        $stored = $this->workspaceValue($workspace, 'mode');

        return is_string($stored)
            ? (BillingMode::tryFrom($stored) ?? BillingMode::PrepaidCredits)
            : BillingMode::PrepaidCredits;
    }

    /**
     * How much is settled at once — a session, half a month, a month.
     *
     * Independent of the mode: a workspace can take prepaid credits monthly, or
     * defer a single session. See {@see BillingCadence} for why the two are not
     * one enum.
     */
    public function cadence(Workspace $workspace): BillingCadence
    {
        $stored = $this->workspaceValue($workspace, 'cadence');

        return is_string($stored)
            ? (BillingCadence::tryFrom($stored) ?? BillingCadence::Session)
            : BillingCadence::Session;
    }

    /**
     * How large a ceiling this workspace's CADENCE justifies — one of two
     * factors, never the answer on its own.
     *
     * ⚠️ NOT "the ceiling a student starts with". Q-9 and FR-048 make consent
     * the gate: a student with no recorded agreement to deferred payment starts
     * at ZERO however generous the cadence is, and {@see self::initialLimitCredits()}
     * is that half. Two methods, two questions, composed in
     * {@see self::initialLimitFor()} — which US9 wrote as `min`, not the `max`
     * this note used to claim. Under a monthly cadence `max` opens with four
     * credits of debt and Q-9 says one; it also hands the student the whole
     * ladder on day one, so "+1 after three on-time payments" could never move a
     * ceiling in the workspaces where it mattered most.
     *
     * ⚠️ AN INITIAL, NOT A CAP — corrected when US6 came to apply it. An earlier
     * note here wrote the composition as `min(cadenceAllows, max)` and called it
     * the ceiling, which under the DEFAULT `Session` cadence pins every student at
     * one credit for ever: Q-9's "+1 after three on-time payments, up to four"
     * could never move a single ceiling, and the requirement would have read as
     * implemented. The cap is {@see self::maxLimitCredits()} and it is enforced in
     * `SetCreditLimit`, which every writer goes through.
     *
     * ⚠️ AND THE CADENCE STILL DOES NOT DERIVE THE CEILING, which the spec forbids
     * in the paragraph before the one that writes the formula. `min` is what
     * reconciles them: the cadence can only ever LOWER the platform's number, so
     * it bounds the ceiling and never produces one.
     *
     * Zero in a prepaid mode whatever the cadence says: FR-014 forbids going
     * below zero there at all, and a ceiling the floor ignores is a number in
     * the panel that means nothing.
     */
    public function cadenceAllowsCredits(Workspace $workspace): int
    {
        if (! $this->mode($workspace)->allowsDeferral()) {
            return 0;
        }

        return min($this->cadence($workspace)->sessionsPerCycle(), $this->maxLimitCredits());
    }

    public function zeroBalanceBehavior(Workspace $workspace): ZeroBalanceBehavior
    {
        $stored = $this->workspaceValue($workspace, 'zero_balance_behavior');
        $default = (string) config('billing.workspace_defaults.zero_balance_behavior', 'block');

        return ZeroBalanceBehavior::tryFrom(is_string($stored) ? $stored : $default)
            ?? ZeroBalanceBehavior::Block;
    }

    /**
     * Remaining-credit levels that raise an alert, highest first (FR-028).
     *
     * Sorted and de-duplicated here rather than trusted as stored: the tier a
     * balance has reached is its RANK in this list, and a list that arrived out
     * of order would rank a deeper crossing lower than a shallower one — which
     * FR-034's "never repeat the same alert" reads as a crossing already
     * announced.
     *
     * @return list<int>
     */
    public function alertThresholds(Workspace $workspace): array
    {
        $stored = $this->workspaceValue($workspace, 'alert_thresholds');

        if (! is_array($stored)) {
            /** @var array<int, mixed> $stored */
            $stored = (array) config('billing.workspace_defaults.alert_thresholds', [3, 1]);
        }

        $thresholds = array_map('intval', array_filter($stored, 'is_numeric'));
        $thresholds = array_values(array_unique($thresholds));
        rsort($thresholds);

        return $thresholds;
    }

    /**
     * Why this mode cannot be saved, or null when it can (FR-015).
     *
     * A REASON, not a boolean. "Not fully configured" is refused at the Action,
     * which the panel and the API share — and a bare false there becomes "تعذّر
     * الحفظ" on a screen where the person can see nothing wrong with what they
     * typed.
     *
     * ⚠️ THE CADENCE IS NOT ASKED ABOUT, and the missing parameter is the point.
     * FR-010ب refused a non-session cadence under a prepaid mode until Q-11
     * lifted it: prepaid monthly is a shape the product sells, and the cadence
     * there is presentational — which package leads the purchase screen. What it
     * must never do is DERIVE the credit count, the price or the ceiling, and
     * that prohibition lives where each of those is computed, not here. A cadence
     * argument no branch reads would read as a check that still exists.
     */
    public function refusalToAdopt(BillingMode $mode): ?string
    {
        if (! $mode->isReady()) {
            // PAYMENT_GATEWAY names a provider spec 007 has not shipped. Saving
            // it leaves a workspace in a mode with no way to take money at all.
            return 'هذا النمط غير مُهيّأ بعد على المنصة، فلا يمكن اعتماده.';
        }

        return null;
    }

    public function canAdopt(BillingMode $mode): bool
    {
        return $this->refusalToAdopt($mode) === null;
    }

    /**
     * Write the workspace's half. Merged, never replaced.
     *
     * `workspaces.settings` is one JSON column that later specs will also write
     * to; assigning a fresh array here would drop whatever they put beside
     * `billing` the first time a teacher changed their mode.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(Workspace $workspace, array $values): void
    {
        $settings = is_array($workspace->settings) ? $workspace->settings : [];
        $billing = is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : [];

        $settings[self::SETTINGS_KEY] = [...$billing, ...$values];

        $workspace->forceFill(['settings' => $settings])->save();
    }

    // The platform half -------------------------------------------------------

    /**
     * The platform's fixed fee for hosting one session of this type.
     *
     * Per type because hosting a group session costs once, not once per student;
     * a single fee would silently double the margin on every group class. Falls
     * back to the individual fee for a type with no entry of its own, which is
     * the conservative direction — a new session type prices as the dearer one
     * rather than as free.
     */
    public function operatingFeeMinor(ClassSessionType $type): int
    {
        $key = $type === ClassSessionType::Group ? 'group' : 'individual';

        return (int) PlatformSettings::get(
            'billing.operating_fee_minor.'.$key,
            config('billing.operating_fee_minor.'.$key, 0),
        );
    }

    /** Gateway percentage in basis points — 250 = 2.5%. Integer, never a decimal. */
    public function gatewayFeeBps(): int
    {
        return max(0, (int) PlatformSettings::get('billing.gateway_fee_bps', 0));
    }

    public function gatewayFixedFeeMinor(): int
    {
        return max(0, (int) PlatformSettings::get('billing.gateway_fixed_fee_minor', 0));
    }

    public function currency(): string
    {
        return (string) PlatformSettings::get('billing.currency', 'QAR');
    }

    // The credit-limit policy (FR-036 … FR-040) -------------------------------

    /**
     * The platform's half of the opening ceiling — one of two numbers, never the
     * answer alone. {@see self::initialLimitFor()} composes them.
     */
    public function initialLimitCredits(): int
    {
        return max(0, (int) PlatformSettings::get('billing.limit.initial_credits', 1));
    }

    /**
     * The ceiling a balance opens with once a deferred-payment consent exists.
     *
     * `min` of the platform's conservative start (Q-9's ١) and what this
     * workspace's cadence and the platform's cap allow — so a monthly workspace
     * does not open at four and skip the earning ladder, and a prepaid one opens
     * at zero because {@see self::cadenceAllowsCredits()} is zero there.
     *
     * ⚠️ THE CONSENT IS NOT ASKED HERE. This is the number, not the permission:
     * every caller has already answered FR-048 (`SetCreditLimit` by refusing,
     * `CreditAccounts` by asking the registry), and a second copy of the gate
     * inside the arithmetic would be the copy that drifts.
     */
    public function initialLimitFor(Workspace $workspace): int
    {
        return min($this->initialLimitCredits(), $this->cadenceAllowsCredits($workspace));
    }

    public function increaseAfterOnTime(): int
    {
        return max(1, (int) PlatformSettings::get('billing.limit.increase_after_on_time', 3));
    }

    public function increaseByCredits(): int
    {
        return max(0, (int) PlatformSettings::get('billing.limit.increase_by_credits', 1));
    }

    public function maxLimitCredits(): int
    {
        return max(0, (int) PlatformSettings::get('billing.limit.max_credits', 4));
    }

    public function decreaseAfterLateDays(): int
    {
        return max(1, (int) PlatformSettings::get('billing.limit.decrease_after_late_days', 14));
    }

    public function dormantNoticeMonths(): int
    {
        return max(1, (int) PlatformSettings::get('billing.dormant_notice_months', 12));
    }

    // The escrow guards (Q-11) ------------------------------------------------

    /** Days since the last delivered session before a course stops selling (FR-021ط). */
    public function stopSellingAfterDays(): int
    {
        return max(1, (int) PlatformSettings::get(
            'billing.stop_selling_after_days',
            config('billing.stop_selling_after_days', 60),
        ));
    }

    /** Ceiling on unconsumed credits one student may hold on one course (FR-021ي). */
    public function maxUnredeemedCredits(): int
    {
        return max(1, (int) PlatformSettings::get(
            'billing.max_unredeemed_credits',
            config('billing.max_unredeemed_credits', 24),
        ));
    }

    /**
     * How many lots one consumption may draw from.
     *
     * Withdrawal is one conditional UPDATE per lot, so an unbounded loop is an
     * unbounded query count against the NFR-012 budget.
     */
    public function maxLotsPerDraw(): int
    {
        return max(1, (int) PlatformSettings::get('billing.max_lots_per_draw', 20));
    }

    private function workspaceValue(Workspace $workspace, string $key): mixed
    {
        $settings = $workspace->settings;

        if (! is_array($settings) || ! isset($settings[self::SETTINGS_KEY])) {
            return null;
        }

        $billing = $settings[self::SETTINGS_KEY];

        return is_array($billing) ? ($billing[$key] ?? null) : null;
    }
}
