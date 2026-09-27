<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notification_deliveries(status, last_attempted_at)` — the «سجلّ التسليم»
 * badge.
 *
 * `NotificationDeliveryResource::recentFailureCount()` asks
 * `status = 'failed' AND last_attempted_at >= now() - 1 day` on every panel
 * page. `(status, deferred_until)` served the equality and nothing after it, so
 * every failure the table ever recorded was read to count one day's worth — a
 * number that only grows.
 *
 * Named by hand, under MySQL's 64-character identifier limit
 * (`SchemaIdentifierLengthTest`); guarded by `hasIndex()` both ways.
 */
return new class extends Migration
{
    private const INDEX = 'notification_deliveries_status_attempted_index';

    public function up(): void
    {
        if (! Schema::hasIndex('notification_deliveries', self::INDEX)) {
            Schema::table('notification_deliveries', fn (Blueprint $table) => $table->index(['status', 'last_attempted_at'], self::INDEX));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('notification_deliveries', self::INDEX)) {
            Schema::table('notification_deliveries', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
