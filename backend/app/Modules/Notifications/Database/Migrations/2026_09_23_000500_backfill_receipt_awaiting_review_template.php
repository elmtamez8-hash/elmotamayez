<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The officer's «a receipt is waiting» notice reaches an existing database.
 *
 * ⛔ Without this the notice is dropped in silence and every test is green —
 * `TemplateRenderer` refuses a missing row, `DispatchNotification` logs rather
 * than fails, and `tests/Pest.php` seeds the catalogue before every case.
 *
 * `seedMissing()`, never `run()`: every row is editable from `/admin`.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    /** Empty on purpose — see its predecessors. */
    public function down(): void {}
};
