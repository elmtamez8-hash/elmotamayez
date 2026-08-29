<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 011 · T127 — `scheduled_report` reaches a database that already exists.
 *
 * ⚠️ A NOTIFICATION WITH NO TEMPLATE IS DROPPED IN SILENCE. Without this file
 * the sweep would stamp `last_sent_on`, report success, and deliver nothing —
 * and because the stamp is one-way, that period's report could never be re-sent.
 * Fifth instance of this shape in the tree.
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
