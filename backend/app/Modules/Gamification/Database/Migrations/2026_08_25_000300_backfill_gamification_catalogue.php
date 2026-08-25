<?php

declare(strict_types=1);

use Database\Seeders\GamificationCatalogSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Any catalogue row a release adds reaches an existing database.
 *
 * ⚠️ AN ACTION WITH NO ROW AWARDS NOTHING, IN SILENCE. `AwardPoints` looks the key
 * up and returns when there is none — an award for an undefined action is an
 * unfilled catalogue, not an error, and that is the right behaviour. It also means
 * a release that adds an action and seeds nothing ships a feature that is dead on
 * arrival with no error anywhere.
 *
 * ⚠️ THIS WAS NOT HYPOTHETICAL. `helpful_answer` sat in the seeder and was absent
 * from a real database: a teacher endorsed a student's answer, `is_helpful` turned
 * true, the event fired, the listener ran — and zero points were awarded. Found by
 * walking the product by hand (spec 010 · `T186`), never by a test, because
 * `tests/Pest.php` seeds this catalogue before every case, so every assertion
 * about it was made against a table production did not have.
 *
 * ⚠️ AND IT CALLS `seedMissing()`, NEVER `run()`. Every row here is editable from
 * `/admin`, so the seeder's `updateOrCreate` in a deploy path resets every xp
 * value, coin value and daily cap an operator ever tuned. The third instance of
 * this exact shape in this tree — after `NotificationTemplateSeeder` and
 * `DataCategorySeeder` — and the reason each of them now has two modes.
 *
 * `down()` is empty on purpose: removing a catalogue row on a rollback silently
 * stops awarding points that were being awarded before this release.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new GamificationCatalogSeeder)->seedMissing();
    }

    public function down(): void {}
};
