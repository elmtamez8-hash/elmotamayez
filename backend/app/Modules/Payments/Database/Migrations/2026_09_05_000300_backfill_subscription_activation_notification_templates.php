<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The two subscription-activation templates, on a database that already exists.
 *
 * ⚠️ A TYPE SHIPPED WITHOUT ITS TEMPLATE REACHES NOBODY, FOR EVER, IN SILENCE.
 * `TemplateRenderer` refuses a missing row and `DispatchNotification` logs rather
 * than failing the operation that triggered it — so a student whose subscription
 * was just approved would learn nothing at all: no dates, no schedule, no way
 * into the lesson, and no fault reported anywhere. Which is precisely the «بدون
 * تعقيدات» half this feature exists for. And every test would still be green:
 * `tests/Pest.php` seeds the templates before each Feature test, so the suite can
 * never see it.
 *
 * This is the SIXTH file of this exact shape in this tree. The mechanism is now
 * the rule: when you add a row to a runtime catalogue, add the backfill in the
 * same change.
 *
 * `seedMissing()`, never `run()`: the seeder writes with `updateOrCreate`, which
 * on a deploy would replace every wording an admin has edited from the panel with
 * the shipped default. `down()` is empty for the same reason — rolling back must
 * not delete a template somebody rewrote.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    public function down(): void
    {
        //
    }
};
