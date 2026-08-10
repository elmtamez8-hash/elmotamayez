<?php

declare(strict_types=1);

/*
 * Fallbacks only — each of these is a row in `platform_settings` an operator
 * edits from the panel, and this file is what an unseeded database falls back to.
 *
 * Publishing a new version is the whole mechanism FR-049 asks for: a consent is
 * stored with the version that was current when it was signed, and the readers
 * ask for the current one. Bumping the number here (or, in production, in the
 * panel) therefore invalidates every acceptance of the old text the moment it is
 * saved — no migration, no sweep, and nothing to forget to run.
 *
 * ⚠️ AND IT TAKES EFFECT IMMEDIATELY ON THE FLOOR. `CreditLedger::floorForBalance()`
 * asks whether the consent is current, so a bump stops deferral for every student
 * who has not signed the new text — the ceilings stay where they are and start
 * meaning something again the moment each student re-signs.
 */

return [
    'versions' => [
        /*
         * The deferred-payment terms. What a student agrees to when they agree
         * to owe: that sessions taken on credit are a debt, and how it is
         * collected.
         */
        'deferred_payment_terms' => '1.0',

        /*
         * Data processing. Recorded through the same table and read through the
         * same registry, and it never substitutes for the one above in either
         * direction (FR-050) — the readers name the document they are asking
         * about. Spec 013 owns what this one actually says.
         */
        'data_processing' => '1.0',
    ],
];
