<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The WhatsApp row `enrollment_created` needs now that it reaches guardians
 * (owner decision, 2026-09-24).
 *
 * `defaultChannels()` is derived from `targetsGuardians()` and the seeder writes a
 * WhatsApp template for every type whose defaults include it, so an existing
 * database has no row for this one — and `TemplateRenderer` refuses a missing row
 * while `DispatchNotification` logs rather than fails: the guardian's copy would
 * be dropped in silence with every test green (they seed fresh).
 *
 * Its own file rather than an edit to `000600`, which already ran on production.
 * `seedMissing()`, never `run()`: every other row is editable from the panel.
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
