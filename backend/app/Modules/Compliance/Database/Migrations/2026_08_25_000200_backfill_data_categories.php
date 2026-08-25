<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Any catalogue row a release adds reaches an existing database.
 *
 * ⚠️ A CATEGORY WITH NO ROW IS A CATEGORY THE NIGHTLY SWEEP NEVER LOOKS AT.
 * `RunRetentionSweepJob` walks `data_categories`, so a duration declared in the
 * seeder and absent from the table is a retention promise nothing keeps —
 * silently, with the owner registered, the walks implemented and every test green,
 * because `tests/Pest.php` seeds the table before each one. Spec 010's Phase 9
 * added `announcement` and would have shipped exactly that.
 *
 * ⚠️ AND `run()` IS SAFE HERE ONLY BECAUSE IT IS `firstOrCreate` ON THE KEY ALONE.
 * That is the seeder's own deliberate choice — these rows are reference data an
 * operator edits from `/admin`, and a matching `updateOrCreate` would reset every
 * duration a regulator's letter made somebody change. The sibling problem in
 * `NotificationTemplateSeeder` needed a whole second mode for want of it.
 *
 * `down()` is empty on purpose: removing a catalogue row on a rollback would stop
 * sweeping a table that was being swept before this release.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
    }

    public function down(): void {}
};
