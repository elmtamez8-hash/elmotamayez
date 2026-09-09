<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The three reschedule templates, on a database that already exists.
 *
 * ⚠️ A TYPE SHIPPED WITHOUT ITS TEMPLATE REACHES NOBODY, FOR EVER, IN SILENCE.
 * `TemplateRenderer` refuses a missing row and `DispatchNotification` logs rather
 * than failing the operation behind it — so the teacher's queue would never light
 * up and a whole group would never learn their lesson had moved, with nothing
 * anywhere reporting a fault. And every test would still be green: `tests/Pest.php`
 * seeds the templates before each Feature test, so the suite can never see it.
 *
 * `seedMissing()`, never `run()`: the seeder writes with `updateOrCreate`, which
 * on a deploy would replace every wording an admin has edited from the panel.
 * `down()` is empty for the same reason.
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
