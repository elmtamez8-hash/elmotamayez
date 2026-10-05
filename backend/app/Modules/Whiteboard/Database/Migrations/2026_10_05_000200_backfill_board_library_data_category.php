<?php

declare(strict_types=1);

use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The `whiteboard_library_item` data category, delivered to databases that
 * already exist — `DataCategorySeeder::run()` is `firstOrCreate` on the key, so it
 * adds the new row and leaves every edited one alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DataCategorySeeder)->run();
    }

    /** Deliberately empty: `board_library_items` must never sit uncovered by a category. */
    public function down(): void {}
};
