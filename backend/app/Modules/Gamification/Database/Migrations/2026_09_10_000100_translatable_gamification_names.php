<?php

declare(strict_types=1);

use App\Shared\Database\TranslatableColumns;
use Database\Seeders\GamificationCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * `name_ar` becomes a translatable `name` on the three Gamification catalogues.
 *
 * `gamification_actions` is reference data read at run time — `AwardPoints`
 * returns silently when a key is missing — so the shape of this column is the
 * shape every seeder and every backfill migration must write from here on.
 */
return new class extends Migration
{
    private const TABLES = ['gamification_actions', 'levels', 'badges'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            TranslatableColumns::toJson($table, ['name_ar' => 'name']);
        }

        // The backfills dated before this one returned early while the column
        // was still `name_ar` — see {@see TranslatableColumns::converted}. Now that it
        // is here, the catalogue is filled in the same pass.

        (new GamificationCatalogSeeder)->seedMissing();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            TranslatableColumns::toStrings($table, ['name_ar' => 'name']);
        }
    }
};
