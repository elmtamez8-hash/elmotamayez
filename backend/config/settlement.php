<?php

declare(strict_types=1);

/*
 * Fallbacks only.
 *
 * Every one of these is a row in `platform_settings` that an operator edits from
 * the panel; this file is what a database with nothing seeded falls back to. A
 * settlement period length or a compensation rate that can only change by
 * shipping code is a number nobody ever tunes.
 *
 * Read them through Settlement\Support\SettlementSettings, never with config()
 * from an Action.
 */

return [
    // How long a settlement period runs before it is closed and paid.
    'period_days' => 30,

    // What must be delivered before a teaching unit stops being pending.
    //
    // Only "recording" is checkable today: files and homework arrive with spec
    // 008. FR-008أ is itself conditional ("if the course requires them"), so a
    // component that cannot be required yet is not required — suspending a
    // teacher's earning on a feature nobody has built is a deduction with no
    // cause.
    'required_package_components' => ['recording'],

    // A session nobody booked earns nothing by default (FR-008هـ). The switch
    // exists because a teacher who showed up and delivered the package may still
    // deserve something; it is off until an operator decides otherwise.
    'zero_attendance_compensation_enabled' => false,

    // Percent of one seat's rate, 0–100.
    'zero_attendance_compensation_percent' => 0,

    // How often a teacher may ask for a new rate. A price change moves the sale
    // price through the cost-plus formula in 006, so it is not a field to edit
    // at will.
    'rate_requests_per_window' => 1,
    'rate_request_window_days' => 30,

    // One currency in the first release. Amounts are stored as integers in the
    // minor unit (1 QAR = 100), never as floats — a ledger that must match a
    // statement to zero cannot rest on a type that rounds.
    'currency' => 'QAR',
];
