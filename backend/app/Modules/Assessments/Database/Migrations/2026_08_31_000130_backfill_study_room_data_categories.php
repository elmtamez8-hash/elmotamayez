<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 012 · T108 — the two `data_categories` rows US3 adds, delivered to a
 * database that already exists.
 *
 * ⚠️ A DATA CATEGORY WITH NO ROW IS NEVER SWEPT. Without this file every study
 * room and every participation would sit in the database for ever, while
 * `tests/Pest.php` seeds the catalogue before every case and every retention
 * assertion passes against a table production does not have. The fifth catalogue
 * in this tree to need exactly this treatment.
 *
 * ⚠️ AND `DataCategorySeeder::run()` IS ALREADY `firstOrCreate`, so it needs no
 * `seedMissing()` twin — read the seeder before assuming which call is safe. An
 * `updateOrCreate` here would reset a retention an operator shortened on purpose,
 * on every release.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
    }

    /**
     * Deliberately empty. Removing the rows would leave `study_rooms` and
     * `study_room_participants` in the database with no category describing them
     * — the uncovered state `PersonalDataContractCoverageTest` exists to refuse.
     */
    public function down(): void {}
};
