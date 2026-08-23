<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One message — WORKSPACE-owned (layer 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
            | ⚠️ COPIED FROM THE CONVERSATION EXPLICITLY, NEVER FROM THE CONTEXT.
            | The sender is very often a student, and a student is a member of no
            | workspace at all — `WorkspaceContext::id()` is null for them, so
            | `BelongsToWorkspace`'s auto-fill writes nothing, and for a teacher
            | signed into a second workspace it would write the WRONG one from
            | `users.last_workspace_id`. `PostMessage` sets the column from the
            | conversation it already loaded.
            */
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('sender_user_id');

            $table->text('body');

            /*
            | ⚠️ `hidden_at`, DELIBERATELY NOT `deleted_at`. The second name
            | attracts `SoftDeletes`, whose global scope adds `deleted_at IS NULL`
            | to every read — which would erase the moderation archive `FR-015`
            | promises AND silently shorten every page of fifty, since the limit
            | is applied to rows the scope has already removed.
            */
            $table->timestamp('hidden_at')->nullable();

            // Marked useful by the teacher (FR-020). One flag, claimed by a
            // conditional update in the moderation phase.
            $table->boolean('is_helpful')->default(false);

            $table->timestamps();

            /*
            | ⚠️ THE INDEX LEADS WITH `workspace_id` BECAUSE THE GLOBAL SCOPE DOES.
            | Every read by a teacher or an assistant carries that condition
            | whether the query names it or not, so a `(conversation_id, id)` index
            | would be used only partially. The `id` tail is what makes the keyset
            | page an index range rather than a sort.
            */
            $table->index(['workspace_id', 'conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
