<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 011 · T096 — `subscription_expiring` reaches a database that already
 * exists.
 *
 * ⚠️ A NOTIFICATION WITH NO TEMPLATE IS SILENTLY DROPPED. `TemplateRenderer`
 * refuses a missing row (FR-037) and `DispatchNotification` logs rather than
 * failing the sweep that triggered it — so without this file FR-027's «إبلاغ
 * الطالب قبله بمهلة معلنة» would be a job that stamps `expiring_notified_at`,
 * reports success, and delivers nothing. Permanently, and to nobody's
 * knowledge: the stamp is one-way, so the notice for that period can never be
 * re-sent even after the row is added.
 *
 * ⚠️ `seedMissing()`, NEVER `run()` — the seeder writes with `updateOrCreate`,
 * which would reset every Arabic body an operator has tuned from `/admin` on
 * every release. Fourth instance of this shape in the tree.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new NotificationTemplateSeeder)->seedMissing();
    }

    /**
     * Deliberately empty: removing the row restores the silent-drop state this
     * migration exists to end.
     */
    public function down(): void {}
};
