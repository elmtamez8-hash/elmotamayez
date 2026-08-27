<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The three cohort-transfer templates, on a database that already exists.
 *
 * ⚠️ A TYPE SHIPPED WITHOUT ITS TEMPLATE REACHES NOBODY, FOR EVER, IN SILENCE.
 * `TemplateRenderer` refuses a missing row and `DispatchNotification` logs rather
 * than failing the operation that triggered it — so the teacher's queue would
 * simply never light up and the student would never learn their request was
 * refused, with nothing anywhere reporting a fault. Spec 010's first live
 * announcement reached zero of three students exactly this way, on a database
 * whose migrations were fully up to date, while every test was green:
 * `tests/Pest.php` seeds the templates before each Feature test, so the suite
 * can never see it.
 *
 * The August 25th backfill is generic over `NotificationType::cases()` and heals
 * everything that existed then — but a migration that has already run does not
 * run again, so a type added afterwards needs its own file. This is that file.
 *
 * `seedMissing()`, never `run()`: the seeder writes with `updateOrCreate`, which
 * on a deploy would replace every wording an admin has edited from the panel
 * with the shipped default. `down()` is empty for the same reason — rolling back
 * must not delete a template somebody rewrote.
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
