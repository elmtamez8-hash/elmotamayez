<?php

declare(strict_types=1);

use Database\Seeders\DataProcessorSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 011 · T113 — the register of processors gains its seventh row, in the same
 * change that ships the seventh processor.
 *
 * ⚠️ A CATALOGUE ROW A RELEASE ADDS NEVER REACHES AN EXISTING DATABASE, and this
 * tree has now hit that three times before — a notification template, a data
 * category and a gamification action, all reference data read at runtime, all
 * seeded only by `migrate:fresh --seed`, and all SILENT when the row is missing.
 * `data_processors` is the same shape: the privacy screen simply lists one fewer
 * recipient, and the omission looks exactly like a vendor we do not use.
 *
 * `DataProcessorSeeder::run()` is already `firstOrCreate` on the key alone — these
 * rows are reference data at birth and operator data afterwards, so an
 * `updateOrCreate` in a deploy path would reset every purpose sentence an
 * operator ever edited. Calling it here is therefore already the «write only what
 * is absent» mode the other three seeders needed a second method for.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataProcessorSeeder)->run();
    }

    /**
     * ⚠️ NOT REVERSIBLE, AND SAYING SO IS THE POINT. Deleting the row on rollback
     * would delete an operator's edits to it along with ours, and a register that
     * loses a recipient is the failure this migration exists to prevent.
     */
    public function down(): void {}
};
