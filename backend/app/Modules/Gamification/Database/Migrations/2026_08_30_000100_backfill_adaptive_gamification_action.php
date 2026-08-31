<?php

declare(strict_types=1);

use Database\Seeders\GamificationCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 012 · T038 — `concept_mastered` reaches a database that already exists.
 *
 * ⚠️ AN ACTION WITH NO ROW AWARDS NOTHING, IN SILENCE. `AwardPoints` looks the
 * key up and returns when there is none, which is right — an award for an
 * undefined action is an unfilled catalogue, not an error — and which also means
 * shipping the event, the listener and the mastery row without this file is
 * shipping a feature that is dead on arrival with nothing logged anywhere.
 *
 * ⚠️ IT HAS HAPPENED. `helpful_answer` sat in the seeder and was absent from a
 * real database for a whole phase, found by walking the product by hand and never
 * by a test — `tests/Pest.php` seeds the catalogue before every case, so every
 * assertion about it was made against a table production did not have. This is
 * the same shape, written before it can happen again.
 *
 * ⚠️ AND IT CALLS `seedMissing()`, NEVER `run()`: every value in that catalogue is
 * editable from `/admin`, so an `updateOrCreate` in a deploy path resets every xp
 * value, coin value and daily cap an operator ever tuned, on every release.
 *
 * `down()` is empty on purpose — removing the row on a rollback silently stops
 * awarding points that were being awarded before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new GamificationCatalogSeeder)->seedMissing();
    }

    public function down(): void {}
};
