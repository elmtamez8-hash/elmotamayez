<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The WhatsApp row `waitlist_invited` needs now that it reaches guardians.
 *
 * `defaultChannels()` is derived from `targetsGuardians()`, and the seeder writes
 * a WhatsApp template for every type whose defaults include it — so the type
 * gained a channel in this release and an existing database has no row for it.
 * `TemplateRenderer` refuses a missing row and `DispatchNotification` logs
 * rather than fails, so without this the guardian's copy is dropped in silence
 * while every test stays green (they seed the templates fresh).
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
