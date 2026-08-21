<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\DataCategory;
use App\Shared\Support\ErasureMode;
use Database\Seeders\DataCategorySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What erasure does to each category's rows (FR-020 … FR-023).
 *
 * ⚠️ THE CATEGORY DECLARES ITS GRADE, AND THE CONTRACT RECEIVES IT. The third
 * column parallel to `retain_days` and `expiry_behaviour`, and it exists for the
 * same reason: a module that chose its own grade would be a module deciding what
 * the platform is legally obliged to keep. Research §R3 says it in one line —
 * «كلُّ صنفِ بياناتٍ يُعلِن حالتَه، والعقدُ يستقبلها ولا يخترعها».
 *
 * ⚠️ AND THE DEFAULT IS `retain`, WHICH IS THE SAFE DIRECTION AND NOT THE
 * CONVENIENT ONE. Erasure does not reverse, so a row that arrives with no declared
 * grade — a category added by a migration that forgot this column, a seeder run
 * against an older tree — must be kept and reported, never destroyed on a guess.
 * `ExecuteDataErasure` logs when it falls back here rather than proceeding quietly.
 *
 * ⚠️ AND THE VALUES COME FROM `DataCategorySeeder`, NOT FROM A LIST IN THIS FILE.
 * The first version carried its own map of seventeen keys, which was two problems
 * in one: two lists answering a single question diverge at the first category
 * anybody adds, and the copy named `lesson_progress`, `exam_answer` and every
 * other module's table from INSIDE `Compliance` — which `ContextIsolationTest`
 * fails the build over, correctly. The seeder lives in `database/seeders/`, owns
 * the catalogue already, and is the one place these strings belong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_categories', function (Blueprint $table): void {
            $table->enum('erasure_mode', ['delete', 'anonymise', 'retain'])
                ->default('retain')
                ->after('expiry_behaviour');
        });

        /*
        | ⚠️ THE EXISTING ROWS ARE STAMPED HERE, NOT LEFT TO THE SEEDER.
        | `DataCategorySeeder::run()` uses `firstOrCreate` on the key alone —
        | deliberately, so an operator's edits survive a deploy — which means it
        | writes NOTHING to a row that already exists. Seventeen categories are
        | already in every database this ships to; without this loop they would all
        | sit on the `retain` default, and the right to erasure would quietly do
        | nothing at all while every test that seeds a fresh catalogue passed.
        */
        foreach (DataCategorySeeder::categories() as $category) {
            $mode = $category['erasure_mode'];

            DataCategory::query()
                ->where('key', $category['key'])
                ->update(['erasure_mode' => $mode instanceof ErasureMode ? $mode->value : (string) $mode]);
        }
    }

    public function down(): void
    {
        Schema::table('data_categories', function (Blueprint $table): void {
            $table->dropColumn('erasure_mode');
        });
    }
};
