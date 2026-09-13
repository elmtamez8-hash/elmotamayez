<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * ٠٣٥ · T009 — صفّا `data_categories` للجدولَينِ الجديدَين، إلى قاعدةٍ قائمةٍ بالفعل.
 *
 * ⚠️ A DATA CATEGORY WITH NO ROW IS NEVER SWEPT AND NEVER EXPORTED, silently.
 * `tests/Pest.php` seeds the catalogue before every Feature test, so every
 * assertion about these two tables is green against a table production does
 * not have. The sixth catalogue in this tree to need exactly this treatment.
 *
 * ⚠️ AND `DataCategorySeeder::run()` IS ALREADY `firstOrCreate` ON THE KEY
 * (`database/seeders/DataCategorySeeder.php:50`) and guards itself against the
 * translatable conversion (`:38-41`). It needs no `seedMissing()` twin — read
 * the seeder before inventing a second mode. An `updateOrCreate` here would
 * reset a retention an operator shortened on purpose, on every release.
 *
 * ⚠️ AND THE COVERAGE GUARD IS PER MODULE, so a new table inside a module that
 * already implements the contract is invisible to it: nothing would have told
 * us these two rows were missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
    }

    /**
     * Deliberately empty. Removing the rows would leave `credit_holds` and
     * `session_unlocks` in the database with no category describing them.
     */
    public function down(): void {}
};
