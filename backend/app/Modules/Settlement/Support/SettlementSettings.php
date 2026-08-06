<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

use App\Modules\Tenancy\Support\PlatformSettings;

/**
 * The one place this phase's numbers come from.
 *
 * No Action reaches for config() directly: every value here is a
 * `platform_settings` row an operator edits from the panel, falling back to
 * `config/settlement.php`. A period length or a compensation rate that can only
 * change by shipping code is a number nobody ever tunes.
 */
class SettlementSettings
{
    public function periodDays(): int
    {
        return (int) PlatformSettings::get('settlement.period_days', 30);
    }

    public function currency(): string
    {
        return (string) PlatformSettings::get('settlement.currency', 'QAR');
    }

    /**
     * What must be delivered before a unit stops being pending.
     *
     * Only `recording` is checkable today; files and homework arrive with 008.
     * FR-008أ is itself conditional, so a component nobody can require yet is not
     * required — holding a teacher's earning against a feature that does not
     * exist is a deduction with no cause.
     *
     * @return list<string>
     */
    public function requiredPackageComponents(): array
    {
        $value = PlatformSettings::get('settlement.required_package_components', ['recording']);

        return is_array($value) ? array_values(array_map('strval', $value)) : ['recording'];
    }

    public function zeroAttendanceCompensationEnabled(): bool
    {
        return (bool) PlatformSettings::get('settlement.zero_attendance_compensation_enabled', false);
    }

    /** Percent of one seat's rate, clamped to 0–100 so a typo cannot overpay. */
    public function zeroAttendanceCompensationPercent(): int
    {
        $percent = (int) PlatformSettings::get('settlement.zero_attendance_compensation_percent', 0);

        return max(0, min(100, $percent));
    }

    public function rateRequestsPerWindow(): int
    {
        return max(1, (int) PlatformSettings::get('settlement.rate_requests_per_window', 1));
    }

    public function rateRequestWindowDays(): int
    {
        return max(1, (int) PlatformSettings::get('settlement.rate_request_window_days', 30));
    }
}
