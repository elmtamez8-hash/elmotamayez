<?php

declare(strict_types=1);

use Database\Seeders\TaxonomySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 022 · T006 — the taxonomy reaches a database that already exists.
 *
 * ⚠️ WITHOUT THIS FILE, NO TEACHER ON A LIVE DATABASE CAN COMPLETE AN
 * APPLICATION. The subjects and the broad stages were written only by
 * `MarketplaceSeeder`, which `DatabaseSeeder` calls inside
 * `if (! app()->environment('production'))` — so production has never held a
 * single row of either, both signup pickers are empty, and the students' stage
 * field is a required question with no answer available.
 *
 * `seedMissing()`, never `run()` — the seeder's own docblock says why: every row
 * is editable from `/admin`, and an overwrite in the deploy path resets an
 * operator's renaming and reordering on every release.
 *
 * ⚠️ THE TIMESTAMP DELIBERATELY PREDATES `create_school_years`. The subjects and
 * stages must reach a live database on their own; `TaxonomySeeder` skips the
 * years while their table is absent, and `2026_09_01_000200` calls the same
 * method again once it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new TaxonomySeeder)->seedMissing();
    }

    /** Deliberately empty: removing the rows empties every signup picker. */
    public function down(): void {}
};
