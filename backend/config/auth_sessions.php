<?php

declare(strict_types=1);

/*
 * Fallbacks only — spec 038 · FR-005.
 *
 * Both of these are rows in `platform_settings` that an operator edits from the
 * platform-settings screen; this file is what a database with nothing seeded falls
 * back to. A limit that can only change by shipping code is a limit nobody ever
 * tunes.
 *
 * Read them through `Tenancy\Support\PlatformSettings`, never with `config()` from
 * a job or an Action.
 *
 * ⚠️ EVERY PATH HERE MUST RESOLVE TO A NON-NULL VALUE. `PlatformSettings::KEYS`
 * pairs each setting with a config path, `PlatformSettingsTest` walks the whole map
 * asserting the path resolves, and `PlatformSettingsSeeder` writes what it resolves
 * into a NOT NULL column.
 *
 * ⚠️ AND THE RETENTION IS NOT HERE. It is the third of the three numbers the
 * operator edits on that one screen, but it is STORED in
 * `data_categories.retain_days` — `DataCategoryResource` sends it to the «خصوصيّتي»
 * screen beside a sentence, and a row with no duration prints «يُحفظ ما دام الحساب
 * قائماً», which would be a privacy notice that lies. One stored copy, one screen
 * to edit it from.
 */

return [
    /*
    | How many ANONYMISED ended sessions a single account keeps.
    |
    | ⚠️ Zero or less reads as "no cap" and deletes nothing: an operator who
    | empties the field means to switch the feature off, not to empty the table,
    | and the error direction here does not reverse.
    */
    'cap_per_user' => 50,

    /*
    | And how old a row must be before the cap may reach it at all.
    |
    | ⛔ WITHOUT THIS THE CAP IS AN EVIDENCE-DESTRUCTION TOOL. `auth_sessions` is
    | the only sign-in record in the product, and `throttle:auth` allows five
    | attempts a minute — so somebody who has broken into an account can sign in
    | and out fifty times in about ten minutes, and the nightly sweep then erases
    | the entire baseline along with the trace of the first intrusion. Past a
    | floor, no live keyboard can manufacture an eligible row: it has to wait.
    |
    | ⚠️ AND IT IS LONGER THAN THE RETENTION ON PURPOSE. SC-004 promises a capped
    | account can still count its sign-ins across at least three months, and what
    | delivers that is this floor rather than the cap — everything newer survives
    | regardless of how many rows there are.
    */
    'cap_min_age_days' => 180,

    /*
    | How many days a sign-in may go UNUSED before it ends (owner decision
    | 2026-09-23).
    |
    | ⛔ UNTIL THEN A BEARER TOKEN NEVER EXPIRED: `sanctum.expiration` is null, so
    | a token copied off a shared computer opened the account for as long as the
    | account existed. IDLE, not absolute — somebody who opens the product every
    | week is never signed out — and measured from the token's own `last_used_at`,
    | which Sanctum already writes on every request.
    |
    | ⚠️ Zero or less reads as "no limit", as `cap_per_user` does above: an
    | operator who empties the field means to switch the rule off.
    */
    'idle_days' => 30,
];
