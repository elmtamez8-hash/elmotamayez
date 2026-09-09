<?php

declare(strict_types=1);

use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Spec 032 · T033 — `lesson_link_reported` reaches a database that already
 * exists.
 *
 * ⚠️ A NOTIFICATION WITH NO TEMPLATE IS DROPPED IN SILENCE. `TemplateRenderer`
 * refuses a missing row and `DispatchNotification` logs rather than failing, so
 * without this file the report endpoint would answer its constant 202, stamp
 * `link_reported_at` — which is ONE-WAY inside the window — and deliver nothing.
 * The teacher would never learn their lesson is broken, and the stamp would stop
 * the next honest report for twenty-four hours.
 *
 * Green in every test either way: `tests/Pest.php` seeds the templates before
 * every Feature case. Sixth instance of this shape in the tree.
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
