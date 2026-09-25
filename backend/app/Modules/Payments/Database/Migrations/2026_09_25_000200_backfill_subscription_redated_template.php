<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * `subscription_redated` reaches a database that already exists.
 *
 * ⚠️ A NOTIFICATION WITH NO TEMPLATE IS DROPPED IN SILENCE, and every test is
 * green either way: `tests/Pest.php` seeds the templates before every case.
 * `seedMissing()` writes only absent rows, so no template an operator tuned in
 * `/admin` is reset.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    /** Deliberately empty: removing the row restores the silent-drop state. */
    public function down(): void {}
};
