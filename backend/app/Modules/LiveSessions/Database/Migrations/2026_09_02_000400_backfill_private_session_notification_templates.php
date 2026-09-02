<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The four private-session templates, on a database that already exists.
 *
 * ⚠️ A TYPE SHIPPED WITHOUT ITS TEMPLATE REACHES NOBODY, FOR EVER, IN SILENCE.
 * `TemplateRenderer` refuses a missing row and `DispatchNotification` logs rather
 * than failing the operation that triggered it — so a teacher's queue would never
 * light up and a student would never learn their request was refused, with
 * nothing anywhere reporting a fault. And every test would still be green:
 * `tests/Pest.php` seeds the templates before each Feature test, so the suite can
 * never see it. This is the fifth reference catalogue in this tree to need the
 * same file, and the third time a release added a row to one.
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
