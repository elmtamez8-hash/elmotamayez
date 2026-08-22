<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Community — spec 010
|--------------------------------------------------------------------------
|
| ⚠️ THE FIRST THREE VALUES ARE FALLBACKS, NOT THE SETTING. The operator edits
| them from the panel as `platform_settings` rows; this file is what a database
| with nothing seeded reads. Each of those keys must ALSO appear in
| `PlatformSettings::KEYS`, which is an explicit allowlist — a key missing from it
| is neither editable from the panel nor readable through the settings reader, and
| falls back here for ever.
|
| ⚠️ THE LAST TWO ARE NOT SETTINGS AND ARE DELIBERATELY ABSENT FROM THAT LIST.
| A page size and a fan-out chunk are engineering constants: moving either one
| changes the shape of a query or the timeout budget of a job, not a policy an
| operator has an opinion about. `ComplianceSettings::batchSize()` reads its
| batch sizes from config alone for the same reason.
*/

return [

    /*
    | The mutual-rating gate (FR-030).
    |
    | ⚠️ "SESSIONS COUNTED AS ATTENDED", WHICH IS NOT WHAT THE SHIPPED GATE ASKS.
    | `SubmitReview::hasCompletedSessionWith()` requires a COMPLETED ENROLMENT
    | today — so a student who sat four lessons on an active enrolment is refused
    | and a student with a finished enrolment and zero lessons is accepted. Both
    | answers are wrong in the direction that matters, and spec 010 replaces that
    | predicate in place rather than adding a second endpoint beside it.
    |
    | Editable because it is a trust policy, not arithmetic: four is what the
    | requirements document names, and the first month of real ratings is what
    | tells anyone whether four was right.
    */
    'review' => [
        'min_sessions' => 4,

        /*
        | How long one rating period lasts (FR-032 — one rating per student per
        | teacher per period).
        |
        | ⚠️ THE SHIPPED UNIQUE INDEX MAKES THIS REQUIREMENT VACUOUS UNTIL IT
        | CHANGES. `reviews` carries `unique(teacher_profile_id, student_id)`, so
        | there is exactly one row for ever and a second period cannot be entered
        | at all — the requirement is satisfied by an accident that also forbids
        | what it was meant to allow.
        */
        'period_days' => 30,
    ],

    'chat' => [
        /*
        | The per-user send ceiling, read by the NAMED `chat-write` limiter.
        |
        | ⚠️ THE ONE LIMIT IN THIS PHASE THAT IS A ROW, and the other four are
        | literals beside their limiters like every limiter shipped before them.
        | This is the number a spam wave moves and nothing else in the file is:
        | publishing announcements, moderating and rendering a report card are all
        | rare by nature, and a ceiling nobody ever needs to move is a ceiling that
        | belongs next to the code that enforces it.
        |
        | ⚠️ AND `chat-report` IS A SEPARATE BUCKET ON PURPOSE. Somebody throttled
        | for writing must still be able to report abuse — folding the two into one
        | counter means the loudest participant silences the complaint about them.
        */
        'max_messages_per_minute' => 30,

        /*
        | Messages per page. Keyset pagination by `id`, never `paginate()`: the
        | full count is one more query at every fixture size, so a query-budget
        | test measured at two sizes cannot see it, and it drops the timed half of
        | SC-009 on its own.
        */
        'page_size' => 50,
    ],

    'announcements' => [
        /*
        | Recipients per fan-out batch.
        |
        | ⚠️ MEASURED AGAINST `DispatchNotification`, NOT CHOSEN FOR ROUNDNESS. It
        | issues roughly six to eight queries per recipient — `TemplateRenderer`
        | re-reads the template on every call with no memoisation — so three
        | hundred students in one job is about 2,400 queries inside a sixty-second
        | timeout with `tries: 1`. A worker killed mid-batch must not tell anyone
        | twice, which is why each recipient also carries an idempotency key.
        */
        'fanout_chunk' => 100,
    ],
];
