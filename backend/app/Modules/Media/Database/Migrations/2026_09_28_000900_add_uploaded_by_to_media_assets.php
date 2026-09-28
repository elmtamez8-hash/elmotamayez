<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who asked for the upload ticket.
 *
 * ⚠️ A CHAT ATTACHMENT IS COMPLETED BY THE PERSON WHO UPLOADED IT, AND NOTHING
 * RECORDED WHO THAT WAS. A lesson asset is finished by whoever may manage the
 * lesson, so its owner row was enough; a chat asset is owned by the CONVERSATION,
 * which has two ends — and «may post in this thread» alone would let the other
 * end settle (and so probe) a file they never sent. The column is what lets
 * `CompleteChatAttachment` ask «is this yours» instead of «is this your thread».
 *
 * Nullable: every asset written before today has no recorded uploader, and a
 * lesson asset does not need one. `nullOnDelete` because the file belongs to the
 * thread, not to the account — erasing a person must not take the other side's
 * copy of the conversation with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            // The foreign key goes first, in its own statement — see
            // `docs/gotchas/database.md` on dropping an indexed column.
            $table->dropForeign(['uploaded_by_user_id']);
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn('uploaded_by_user_id');
        });
    }
};
