<?php

declare(strict_types=1);

use App\Shared\Database\TranslatableColumns;
use Database\Seeders\RegionSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * `name_ar` becomes a translatable `name` on the four Marketplace catalogues.
 *
 * The mechanism, the three statements and why the backfill is PHP are all
 * written once, in `TranslatableColumns`. None of these four columns carries an
 * index of its own — the uniques are on `slug` and on `(workspace_id, slug)` —
 * so no `$indexed` argument is needed here.
 */
return new class extends Migration
{
    private const TABLES = ['subjects', 'grade_levels', 'regions', 'school_years'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            TranslatableColumns::toJson($table, ['name_ar' => 'name']);
        }

        // The backfills dated before this one returned early while the column
        // was still `name_ar` — see {@see TranslatableColumns::converted}. Now that it
        // is here, the catalogue is filled in the same pass.

        (new TaxonomySeeder)->seedMissing();
        (new RegionSeeder)->seedMissing();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            TranslatableColumns::toStrings($table, ['name_ar' => 'name']);
        }
    }
};
