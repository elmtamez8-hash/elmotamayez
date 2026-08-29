<?php

declare(strict_types=1);

/*
 * Fallback only — this is a row in `platform_settings` an operator edits from
 * the panel. `config/` is what a database with nothing seeded answers with.
 *
 * See the repository rule: operational numbers live in `platform_settings`, not
 * in `config/`, because a limit that can only change by shipping code is a limit
 * nobody ever tunes.
 */

return [
    /*
    | How many days before the end a student is warned (FR-027's «مهلة معلنة»).
    |
    | Zero switches the notice off. The notice is stamped once per subscription
    | (`expiring_notified_at`), so shortening this later does not re-send to
    | anybody already told — which is the intended asymmetry: a lost reminder
    | beats one every night.
    */
    'expiring_notice_days' => 3,
];
