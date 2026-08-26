<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The teacher's «أغلق النقاش» — one room, for as long as they want it shut.
     *
     * A COLUMN rather than a derived condition, unlike every other reason
     * `ConversationPolicy::post()` refuses: a ban is a workspace-wide fact, a
     * teacher's departure is read from their offboarding, an ended relationship
     * is read from the enrolment. A lock is none of those — it is a decision
     * somebody made about ONE room at ONE moment, and there is nothing else in
     * the system it could be inferred from.
     *
     * ⚠️ A TIMESTAMP AND NOT A BOOLEAN, because «مقفول» is a question a teacher
     * asks about a lesson that ended three hours ago as much as about this one:
     * `true` cannot say when, and a moderation record that cannot say when is a
     * record nobody can argue with.
     *
     * Not indexed: it is read through a conversation already resolved by uuid or
     * by id, never scanned for.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('last_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('locked_at');
        });
    }
};
