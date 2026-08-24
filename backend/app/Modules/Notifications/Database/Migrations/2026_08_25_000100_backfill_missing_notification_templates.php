<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Give a LIVE database the templates for every type that has none.
 *
 * ⚠️ A NOTIFICATION WITH NO TEMPLATE IS DROPPED IN SILENCE, and that is what
 * makes this a migration rather than a line in a deployment runbook.
 * `TemplateRenderer` refuses a missing or unapproved row and
 * `DispatchNotification` LOGS it rather than failing the operation that
 * triggered it — deliberately, so a template fault cannot break an enrolment or
 * a payment. The price is that a type shipped without its row reaches nobody,
 * for ever, with nothing on any screen saying so.
 *
 * ⚠️ MEASURED, NOT REASONED ABOUT. Spec 010's first live announcement reached
 * ZERO of three students on a development database whose migrations were fully
 * up to date. Every test was green: `tests/Pest.php` seeds the templates before
 * each Feature test, so the suite can never see this. The only thing that found
 * it was publishing one and counting the rows.
 *
 * ⚠️ AND IT CALLS `seedMissing()`, NEVER `run()`. The seeder writes with
 * `updateOrCreate` — correct for `migrate:fresh --seed` and wrong here: run on a
 * deploy it would replace every wording an admin has edited from the panel with
 * the shipped default, silently, on every release. `seedMissing()` is
 * `firstOrCreate`, so it fills gaps and touches nothing that exists.
 *
 * ⚠️ AND IT IS GENERIC OVER `NotificationType::cases()` rather than naming the
 * two announcement types this release added. `chat_message` and
 * `periodic_review_published` never got a backfill either, and any database
 * carrying them without rows is in exactly the same silent state — one file
 * heals all of it. A type added AFTER this migration needs its own, the same way
 * five permission backfills each needed theirs.
 *
 * `down()` is empty on purpose: the rows are reference data other releases and
 * an admin's own edits now depend on, and rolling this back must not delete a
 * template somebody rewrote.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    public function down(): void
    {
        //
    }
};
