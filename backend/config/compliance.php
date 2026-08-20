<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Compliance — spec 013
|--------------------------------------------------------------------------
|
| ⚠️ EVERY VALUE HERE IS A FALLBACK, NOT THE SETTING. The operator edits these
| from the panel as `platform_settings` rows; this file is what a database with
| nothing seeded reads. The repository rule is written in `config/media.php` for
| the same reason: a deadline that can only change by shipping code is a deadline
| nobody adjusts the day the regulator changes its guidance.
|
| Each key must ALSO appear in `PlatformSettings::KEYS`, which is an explicit
| allowlist — a key missing from it is neither editable from the panel nor
| readable through the settings reader, and falls back here for ever.
*/

return [

    /*
    | The legal answering deadline (FR-043), in days. `due_at` is derived from it
    | at creation and indexed with the status, because "which request is late"
    | is a question asked on every officer's screen refresh.
    */
    'request_due_days' => 30,

    /*
    | How long a finished export stays downloadable (FR-018).
    |
    | ⚠️ SHORTER THAN ANY OTHER SIGNED LINK IN THE PRODUCT, deliberately. A
    | playback grant exposes one lesson; this file is everything the platform
    | knows about one minor in a single object.
    */
    'export_ttl_hours' => 48,

    /*
    | When a `processing` request is considered stalled and re-dispatched.
    |
    | ⚠️ THE JOB RUNS WITH `tries: 1` AND NOTHING ELSE SWEEPS THIS STATE. Without
    | the sweep a killed worker leaves the request `processing` for ever while
    | `due_at` passes and nobody is told — the `recording_status = 'ingesting'`
    | family exactly: a state written before a killable call, and a sweep that
    | asks about a different value.
    */
    'stalled_after_minutes' => 30,

    /*
    | Batch sizes for the walk. The shipped precedents are the same numbers:
    | `PruneOldNotificationsJob` deletes in thousands, and an anonymising pass
    | keeps the row so it needs a cursor and a smaller page.
    */
    'batch' => [
        'delete' => 1000,
        'anonymise' => 200,
        // Parent-id lists (enrollment ids, attempt ids) are the large object in a
        // two-level `whereIn`, not the walk — `lesson_progress`, `exam_answers`
        // and `attempt_items` carry no user column at all.
        'ids' => 500,
    ],

    /*
    | Floor and ceiling for a category's retention, enforced in the Action.
    |
    | ⚠️ ZERO IS NOT "IMMEDIATELY", it is erasing the platform's data in one
    | night — and `SeedCommand` runs every seeder inside `Model::unguarded()`, so
    | a rule in validation alone is bypassed by a door that is already open.
    |
    | ⚠️ AND THE CEILING IS A COLUMN WIDTH, not a preference:
    | `unsignedSmallInteger` tops out at 65,535, and beyond that
    | `created_at + INTERVAL n DAY` overflows the year 9999 and raises MySQL
    | ERROR 1441, which kills the whole sweep. SQLite returns NULL instead, so
    | nothing expires and nothing errors — no local run shows either direction.
    */
    'retain_days' => [
        'min' => 1,
        'max' => 65535,
    ],

    /*
    | How long the sweep's overlap lock survives.
    |
    | ⚠️ `withoutOverlapping()` ON THE SCHEDULER GUARDS THE DISPATCH, NOT THE RUN:
    | the lock is taken and released around `dispatchToQueue()` in milliseconds,
    | so last night's sweep still walking when tonight's begins gives two workers
    | on the same rows. The fix is `WithoutOverlapping` on the JOB — and it needs
    | an explicit `expireAfter()`, because a job lock never expires on its own and
    | a killed worker would silence the sweep permanently.
    */
    'sweep_lock_minutes' => 180,

    /*
    | Breach handling deadlines (FR-040), in hours.
    */
    'breach' => [
        'authority_notice_hours' => 72,
        'subject_notice_hours' => 72,
    ],

    /*
    | The notice a departing teacher's students get before access ends (FR-033).
    */
    'offboarding_notice_days' => 30,

    /*
    | The disk the export archives are written to.
    |
    | ⚠️ PRIVATE, NEVER `public`. `storage:link` serves the public disk over HTTP
    | with no authentication at all, and this archive is the one object in the
    | product where a guessable path would hand over a child's whole record.
    */
    'export_disk' => env('COMPLIANCE_EXPORT_DISK', 'local'),
];
