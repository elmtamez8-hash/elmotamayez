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

    /*
    | How many failed recordings in one window before the PLATFORM is told, rather
    | than only the teachers (019 FR-009ب).
    |
    | ⚠️ A SEPARATE SIGNAL FROM THE PER-TEACHER NOTIFICATION, DELIBERATELY. Forty
    | notifications to forty teachers show no one of them that there is one cause;
    | each reads as their own bad luck, and the provider outage behind all of them
    | is discovered by whoever happens to add the numbers up. This is the number
    | that says "stop looking at sessions and look at the provider".
    */
    'recording_failure_alert_threshold' => 3,
    'recording_failure_alert_window_hours' => 6,

    /*
    | The private-session request (023 · FR-022ب · FR-023).
    |
    | How long a request waits for an answer before it expires itself, and how
    | many a student may have outstanding with one teacher at a time. Both are
    | judgements about one teacher's inbox that the first month of real use is
    | what settles — which is exactly the shape a release-only constant gets
    | wrong for ever.
    |
    | The ceiling is what makes the request affordable to refuse: it holds no
    | seat and moves no credit (FR-017), which is also what makes it cheap
    | enough to flood a teacher's whole week with in a minute.
    */
    'private_request_ttl_hours' => 48,
    'private_request_max_pending' => 3,

    // How long a join ticket is good for, in minutes (017 FR-007).
    //
    // The library's own default is SIX HOURS, and the contract test asserted
    // "less than 24 hours" — so a forgotten setTtl() shipped green with a
    // ticket that opens the room long after the session ended.
    'ticket_ttl_minutes' => 10,

    // The room's ceiling, passed to the provider rather than only declared
    // (017 FR-003 forbids a constant in the adapter).
    'max_participants' => 50,

    /*
     * Provider credentials. Environment only — never platform_settings: a
     * credential editable from the admin panel is a credential in the database
     * and in every backup of it.
     */
    'livekit' => [
        'url' => env('LIVEKIT_URL'),
        'key' => env('LIVEKIT_API_KEY'),
        'secret' => env('LIVEKIT_API_SECRET'),

        // Where Egress writes. An S3-compatible bucket we own — the provider
        // holds the write credential, and the file enters 004's asset pipeline
        // from there. Bunny is not S3-compatible, which is why this is R2 and
        // not the delivery destination itself (Q8).
        'egress' => [
            'bucket' => env('LIVEKIT_EGRESS_BUCKET'),
            'endpoint' => env('LIVEKIT_EGRESS_ENDPOINT'),
            'region' => env('LIVEKIT_EGRESS_REGION'),
            'key' => env('LIVEKIT_EGRESS_KEY'),
            'secret' => env('LIVEKIT_EGRESS_SECRET'),
        ],
    ],
];
