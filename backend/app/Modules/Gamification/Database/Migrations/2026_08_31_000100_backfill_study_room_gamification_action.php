<?php

declare(strict_types=1);

use Database\Seeders\GamificationCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 012 · T106 — `study_room_finished` reaches a database that already exists.
 *
 * ⚠️ AN ACTION WITH NO ROW AWARDS NOTHING, IN SILENCE. `AwardPoints` looks the key
 * up and returns when there is none — right, because an award for an undefined
 * action is an unfilled catalogue rather than an error — and it also means
 * shipping the event, the listener and the `finished_at` stamp without this file
 * is shipping a feature that is dead on arrival with nothing logged anywhere.
 *
 * ⚠️ IT HAS HAPPENED IN THIS TREE. `helpful_answer` sat in the seeder and was
 * absent from a real database for a whole phase; every test passed because
 * `tests/Pest.php` seeds the catalogue before every case, so every assertion
 * about it was made against a table production did not have.
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
