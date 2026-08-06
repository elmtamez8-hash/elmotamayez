<?php

declare(strict_types=1);

/*
 * Fallbacks only.
 *
 * Every one of these is a row in `platform_settings` that an operator edits from
 * the panel; this file is what a database with nothing seeded falls back to. A
 * limit that can only change by shipping code is a limit nobody ever tunes —
 * and FR-021أ forbids hard-coding the attendance ladder's numbers outright.
 *
 * Read them through LiveSessions\Support\SessionSettings, never with config()
 * from an Action.
 */

return [
    // Which broadcast provider to bind. 'null' needs no account and no network.
    'provider' => env('BROADCAST_PROVIDER', 'null'),

    // One declared timezone for the whole product in the first release. Times are
    // stored UTC; this is what they are displayed and counted down in.
    'timezone' => env('SESSIONS_TIMEZONE', 'Asia/Qatar'),

    // Arrive within this many minutes of the start and you are Present, not Late.
    'grace_minutes' => 5,

    // No ping by this fraction of the session's length and the seat is marked
    // Absent — at that moment, not at the end (FR-021ب).
    'absence_threshold_ratio' => 0.5,

    // How much of the session a student must actually stay for to count Present.
    'required_stay_ratio' => 0.5,

    // And the teacher, for the session to count as delivered (FR-056).
    'teacher_required_stay_ratio' => 0.8,

    // Cancel before this and the seat is released; after it the seat is billed
    // and the seat count is frozen (FR-009 · FR-059).
    'cancellation_window_minutes' => 1440,

    // The room accepts nobody outside this window either side of the start.
    'join_window_minutes' => 15,

    // The presence heartbeat. Doubles as the cap on how much a single ping may
    // add, which is what stops a disconnection gap counting as attendance.
    'presence_interval_seconds' => 30,

    // After this, a manual attendance edit needs a higher administrative
    // permission rather than the teacher's own (FR-022ب).
    'attendance_edit_window_hours' => 48,

    // How long after a session closes its report goes out to guardians.
    'report_delay_minutes' => 15,

    // Give up ingesting a recording after this many attempts, then tell the
    // teacher and offer a manual upload (FR-031).
    'recording_max_attempts' => 5,
];
