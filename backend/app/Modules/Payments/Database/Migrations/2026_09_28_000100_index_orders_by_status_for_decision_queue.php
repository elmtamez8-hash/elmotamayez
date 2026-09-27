<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `orders(status)` — the «اعتماد المدفوعات» badge and its dashboard card.
 *
 * `OrderResource::pendingCount()` and `refundDueCount()` ask `status = ?`
 * across EVERY workspace, on every panel page (the badge renders in the menu).
 * Each existing composite leads with something else — `(workspace_id, status)`,
 * `(workspace_id, kind, status)`, `(kind, status, created_at)` — and a
 * composite is usable from its leading column only, so the platform-wide count
 * scanned the table.
 *
 * Named by hand, under MySQL's 64-character identifier limit
 * (`SchemaIdentifierLengthTest`); guarded by `hasIndex()` both ways so a
 * re-run over a half-applied deploy repairs rather than fails.
 */
return new class extends Migration
{
    private const INDEX = 'orders_status_index';

    public function up(): void
    {
        if (! Schema::hasIndex('orders', self::INDEX)) {
            Schema::table('orders', fn (Blueprint $table) => $table->index(['status'], self::INDEX));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('orders', self::INDEX)) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }
};
