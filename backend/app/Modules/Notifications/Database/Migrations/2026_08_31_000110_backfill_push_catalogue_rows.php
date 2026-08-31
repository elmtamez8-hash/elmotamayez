<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Database\Seeders\DataProcessorSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 012 · US2 · T073 + T074 — the two catalogue rows this phase adds,
 * delivered to a database that already exists.
 *
 * ⚠️ THE FIFTH TIME THIS TREE HAS NEEDED EXACTLY THIS. `notification_templates`,
 * `data_categories`, `gamification_actions` and `regions` are all read at RUN
 * TIME and all seeded only by `migrate:fresh --seed`, and all but the last go
 * quiet when a row is missing: a notification with no template is dropped, a data
 * category with no row is NEVER SWEPT, an award returns without a word. Here that
 * would mean every `push_subscriptions` row sitting in the database for ever,
 * while `tests/Pest.php` seeds the catalogue before every case and every
 * retention assertion passes against a table production does not have.
 *
 * ⚠️ AND BOTH SEEDERS ARE ALREADY `firstOrCreate` — read them before assuming
 * which call is safe. An `updateOrCreate` here would reset a retention an
 * operator shortened on purpose, on every release.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
        (new DataProcessorSeeder)->run();
    }

    /**
     * Deliberately empty. Removing the category would leave `push_subscriptions`
     * in the database with nothing describing it — the uncovered state
     * `PersonalDataContractCoverageTest` exists to refuse — and removing the
     * processor row would fail `ProcessorAllowlistTest` while the channel is
     * still tagged.
     */
    public function down(): void {}
};
