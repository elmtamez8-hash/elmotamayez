<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 011 · T039 — the two templates this module adds, delivered to databases
 * that already exist.
 *
 * ⚠️ A NOTIFICATION WITH NO TEMPLATE IS SILENTLY DROPPED. `TemplateRenderer`
 * refuses to render a missing row (FR-037) and `DispatchNotification` logs it
 * rather than failing the sale that triggered it — so without this file every
 * shipment update and every sold-out refusal would vanish, on a live database,
 * while `tests/Pest.php` seeds the templates before every case and every
 * assertion in the suite passes.
 *
 * ⚠️ `seedMissing()`, NEVER `run()`. The seeder writes with `updateOrCreate`, so
 * a deploy-time `run()` resets every Arabic body an operator has tuned from
 * `/admin`, on every release.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    /**
     * Deliberately empty: removing the rows would restore the silent-drop state
     * this migration exists to end.
     */
    public function down(): void {}
};
