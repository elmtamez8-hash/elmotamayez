<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cohorts — spec 021
|--------------------------------------------------------------------------
|
| ⚠️ FALLBACKS, NOT THE SETTING. Both values are `platform_settings` rows an
| operator edits from the panel; this file is what a database with nothing seeded
| reads. Both keys also appear in `PlatformSettings::KEYS`, which is an explicit
| allowlist — a key missing from it is neither editable from the panel nor
| readable through the settings reader, and falls back here for ever.
*/

return [

    /*
    | How many students a newly created group holds.
    |
    | A DEFAULT, not a ceiling: a teacher setting up a group names its own
    | capacity, and null there means "no declared limit" — which the API sends as
    | `seats_left: null` rather than as a number nobody promised.
    */
    'default_capacity' => 20,

    /*
    | How long «منع مؤقّت من الكتابة» lasts when the teacher gives no duration.
    |
    | Twenty minutes because the instrument is meant for one moment in one lesson.
    | An open-ended ban is still available and is a deliberate second decision —
    | the difference between "settle down" and "you are out of this thread".
    */
    'default_chat_ban_minutes' => 20,

];
