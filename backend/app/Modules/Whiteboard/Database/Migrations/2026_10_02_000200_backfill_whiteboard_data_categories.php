<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 039 — the `whiteboard_board` data category, delivered to databases that
 * already exist. A catalogue row a release adds never reaches a live database on
 * its own (the Store backfill explains); `DataCategorySeeder::run()` is
 * `firstOrCreate`, so it adds the new row and leaves every edited one alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
    }

    /** Deliberately empty: `boards` must never sit uncovered by a category. */
    public function down(): void {}
};
