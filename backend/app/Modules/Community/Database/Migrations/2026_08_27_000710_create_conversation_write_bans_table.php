<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «لا تكتب في هذا الخيط حتى الساعة الخامسة» (FR-047).
 *
 * ⚠️ A THIRD INSTRUMENT, NOT A SECOND USE OF ONE THAT EXISTS (research §R8). The
 * lock silences a whole class to reach one person; the workspace BAN covers every
 * thread with that teacher for ever and is the wrong weight for one argument in
 * one room. This is the middle: one thread, one person, with an end.
 *
 * ⚠️ `conversation_id`, NEVER `workspace_id` ALONE — and that column is what
 * makes «the ban does not follow you into your new group» true by construction
 * rather than by a rule somebody has to remember when transfers are written.
 *
 * `reason` is NOT NULL: a silent refusal reads as a fault and is retried for
 * ever, which is the whole lesson of FR-028ح one requirement away.
 *
 * `expires_at` is nullable and null means OPEN — lifted by hand. The reader
 * judges that in PHP; see `WriteBanReader`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_write_bans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('issued_by');
            $table->string('reason', 500);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->unsignedBigInteger('lifted_by')->nullable();
            $table->timestamps();

            // The reader's whole query: this thread, these people, newest first.
            $table->index(['conversation_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_write_bans');
    }
};
