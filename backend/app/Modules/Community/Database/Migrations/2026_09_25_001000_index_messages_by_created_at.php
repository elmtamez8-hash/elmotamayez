<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `messages (created_at)` — for the nightly retention sweep, and nothing else.
 *
 * `CommunityPersonalData::expire('chat_message')` deletes
 * `WHERE created_at < ? [AND sender_user_id NOT IN (…)] LIMIT n` across every
 * workspace, every night. The only index on the table leads with `workspace_id`
 * (the chat reads need it that way), so the sweep read the whole table to find
 * the few rows past their retention — and on MySQL's REPEATABLE READ a DELETE
 * that scans takes next-key locks on every row it walks, while students are
 * posting into those same conversations.
 *
 * Hand-named: MySQL refuses an identifier over 64 characters and SQLite has no
 * limit, so a generated name is only ever checked by the deploy. This is 25.
 */
return new class extends Migration
{
    private const INDEX = 'messages_created_at_index';

    public function up(): void
    {
        if (Schema::hasIndex('messages', self::INDEX)) {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->index('created_at', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('messages', self::INDEX)) {
            return;
        }

        Schema::table('messages', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
    }
};
