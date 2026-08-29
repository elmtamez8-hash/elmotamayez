<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 011 · T032 — the two `data_categories` rows this module adds, delivered
 * to databases that already exist.
 *
 * ⚠️ A CATALOGUE ROW A RELEASE ADDS NEVER REACHES A LIVE DATABASE ON ITS OWN,
 * AND THIS TREE HAS PAID FOR IT THREE TIMES. `NotificationTemplateSeeder`,
 * `DataCategorySeeder` and `GamificationCatalogSeeder` are all reference data
 * read at run time, all seeded only by `migrate:fresh --seed`, and all silent
 * when a row is missing — a notification with no template is dropped, an award
 * with no action key returns without a word, and a data category with no row is
 * NEVER SWEPT. The last one is this file's business: without it the shipping
 * address of every printed order would sit in the database for ever while
 * `tests/Pest.php` seeds the row before every case and every assertion passes.
 *
 * ⚠️ AND `DataCategorySeeder::run()` IS ALREADY `firstOrCreate`, so it needs no
 * second `seedMissing()` mode — which is the whole point of that mode elsewhere.
 * Every row in this catalogue is editable by an operator, and a deploy-time
 * `updateOrCreate` would reset a retention somebody shortened on purpose, on
 * every release. Read the seeder before assuming which call is safe here.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
    }

    /**
     * Deliberately empty. Removing the rows would leave `store_orders` and
     * `shipments` in the database with no category describing them — the
     * uncovered state `PersonalDataContractCoverageTest` exists to refuse.
     */
    public function down(): void {}
};
