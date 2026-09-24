<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `payment_reminder` leaves the enum, and every row naming it leaves with it.
 *
 * Declared in 003 ahead of spec 006 and never sent by anything: 006 shipped
 * the balance ladder (`credit_balance_low` · `credit_balance_critical` ·
 * `access_withheld`) instead. Owner decision, 2026-09-24.
 *
 * ⚠️ THE ROWS ARE DELETED, NOT LEFT, BECAUSE THEY WOULD NO LONGER READ.
 * `Notification::type()`, `NotificationPreference::type()` and the Filament
 * template and delivery columns all read the column through
 * `NotificationType::from()`, which throws a `ValueError` on a value the enum
 * no longer has — taking the whole screen with it, not the one row. Nothing
 * ever produced one in production, so the
 * expected count is zero; a row that does exist came from a fixture or a
 * hand-written insert, and it is a reminder, not a record — nothing else
 * points at it.
 *
 * `DB::table()` throughout, never the models: a migration speaks the schema
 * of its own date, and a model speaks today's. Deliveries are deleted
 * explicitly before their notifications rather than trusting the cascade, so
 * the order is the same on an engine whose foreign keys are off.
 */
return new class extends Migration
{
    private const TYPE = 'payment_reminder';

    public function up(): void
    {
        DB::table('notification_deliveries')
            ->whereIn('notification_id', DB::table('notifications')->select('id')->where('type', self::TYPE))
            ->delete();

        DB::table('notifications')->where('type', self::TYPE)->delete();
        DB::table('notification_preferences')->where('type', self::TYPE)->delete();

        // Both channels: the type targeted guardians, so it carried a WhatsApp
        // row beside its in-app one.
        DB::table('message_templates')->where('type', self::TYPE)->delete();
    }

    /**
     * ⚠️ CANNOT RESTORE, AND DOES NOT PRETEND TO. The enum case is gone, so a
     * re-inserted template would name a type nothing can send, and the deleted
     * notifications are not recoverable from anywhere. Rolling back the code
     * that removed the case is what would bring the template back —
     * `NotificationTemplateSeeder::seedMissing()` writes it on the next
     * backfill.
     */
    public function down(): void {}
};
