<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The template `session_reschedule_expired` needs on an existing database.
 *
 * `TemplateRenderer` refuses a missing row and `DispatchNotification` logs rather
 * than fails, so without this every expiry notice would be dropped in silence on
 * production while every test stays green (they seed the templates fresh).
 *
 * `seedMissing()`, never `run()`: the other rows are editable from the panel,
 * and an overwrite would reset every wording an operator changed. `down()` is
 * empty for the same reason.
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
