<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bell's two reads that the existing indexes could not order or group.
 *
 * `(recipient_user_id, id)` — the feed (`NotificationController::index()`) and
 * `User::notifications()` are `WHERE recipient_user_id = ? ORDER BY id DESC`
 * with no `read_at` equality. `(recipient_user_id, read_at, id)` puts `read_at`
 * between the two, so the unfiltered feed could not be read in id order from it
 * and paid a filesort over the reader's whole history on every page.
 *
 * `(recipient_user_id, type, read_at)` — `categoriesFor()`, run on EVERY feed
 * page, is `GROUP BY type` with `SUM(CASE WHEN read_at IS NULL …)` over the
 * reader's rows: answered from this index alone, in group order. It also serves
 * the `type` / category filter on the feed and the digest lookup in
 * `DispatchNotification` (`recipient_user_id = ? AND type = ?`). The unread
 * COUNT is not its job — `(recipient_user_id, read_at, id)` already answers it.
 *
 * `recipient_user_id` is a foreign key; the existing composite already carries
 * it leftmost, which is what MySQL requires, so nothing here touches that.
 */
return new class extends Migration
{
    private const FEED = 'notifications_recipient_feed_index';

    private const CATEGORIES = 'notifications_recipient_type_read_index';

    public function up(): void
    {
        if (! Schema::hasIndex('notifications', self::FEED)) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->index(['recipient_user_id', 'id'], self::FEED);
            });
        }

        if (! Schema::hasIndex('notifications', self::CATEGORIES)) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->index(['recipient_user_id', 'type', 'read_at'], self::CATEGORIES);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('notifications', self::CATEGORIES)) {
            Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex(self::CATEGORIES));
        }

        if (Schema::hasIndex('notifications', self::FEED)) {
            Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex(self::FEED));
        }
    }
};
