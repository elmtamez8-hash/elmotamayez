<?php

declare(strict_types=1);

use Database\Seeders\TaxonomySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 022 · T020 — the fourteen school years reach a database that exists.
 *
 * The taxonomy backfill beside this file (`2026_08_31_000100`) runs BEFORE
 * `create_school_years` and skips the years for that reason — Laravel orders
 * migrations by filename, and the subjects and stages have to land whether or
 * not the new table ever does. This one runs after the table and calls the same
 * method again; `firstOrCreate` makes the repeat free.
 *
 * `seedMissing()`, never `run()`: every row is editable from `/admin`, and an
 * overwrite here would reset an operator's renaming on every release.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new TaxonomySeeder)->seedMissing();
    }

    /** Deliberately empty: removing the rows empties the students' picker. */
    public function down(): void {}
};
