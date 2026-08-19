<?php

declare(strict_types=1);

/*
| Spec 009 — fallbacks, not settings.
|
| Every number here is a `platform_settings` row an operator edits from /admin;
| this file is what answers when the database has nothing seeded, exactly as
| `config/media.php` backs MediaLimits and `config/sessions` backs
| SessionSettings. A limit that can only change by shipping code is a limit
| nobody ever tunes — and the initial action values are explicitly provisional
| (Q4: "adjusted after the first month of real behaviour").
|
| ⚠️ NO TIMEZONE KEY HERE. The day and week boundary reads the existing
| `sessions.timezone` row (research §R4). A third declaration of the platform's
| timezone is the exact mistake that would let the daily cap drift away from the
| class schedule in silence.
*/

return [

    /*
    | The level band a student competes inside (FR-023 · SC-009).
    |
    | Slicing by rank alone passes "at most fifty" literally and puts a level-2
    | student having a good week among level-40 grinders — the opposite of why
    | the slice exists. Band first, rank window second.
    */
    'level_band_width' => 5,

    /** Rows returned by one leaderboard read. Never more than fifty (FR-023). */
    'leaderboard_window' => 50,

    /** How long a finished leaderboard period is kept before the sweep (FR-026). */
    'leaderboard_retention_days' => 180,

    /*
    | The ceiling on a reward's monthly cap.
    |
    | The cap itself is the teacher's; this bound is the platform's. A limit a
    | teacher sets for themselves is not a control (FR-031).
    */
    'max_monthly_cap' => 200,

    /** Bounds on one focus session, enforced in the FormRequest AND the Action. */
    'focus_min_minutes' => 5,
    'focus_max_minutes' => 180,

];
