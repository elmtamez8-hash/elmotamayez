<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is explicitly a party to a conversation, and how far they have read.
 *
 * ⚠️ THE STUDENT'S SIDE IS A ROW HERE; THE TEACHER'S SIDE IS NOT. A student
 * issues no `workspace_id` condition of their own — they are a member of nothing
 * — so their list has to be a filter on THIS table or it returns every private
 * conversation on the platform with a 200 beside it. The teacher's side is
 * derived instead, from workspace membership narrowed by the assistant scope:
 * writing a row per assistant would mean maintaining it on every invitation and
 * every revocation, and a stale one is a reader the door has already refused.
 *
 * ⚠️ AND NO `muted_at`. A second mute beside `notification_preferences` — which
 * is platform-owned and already the one place a person silences a type — would
 * be a second answer to one question, with no screen reading either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');

            // How far this person has read. Nullable: joining a room reads
            // nothing, and a zero would be a message id nobody has.
            $table->unsignedBigInteger('last_read_message_id')->nullable();

            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);

            /*
            | ⚠️ THE SECOND INDEX IS THE ONE THE SCREEN ASKS FOR — «which
            | conversations am I in?». The unique above leads with
            | `conversation_id` and cannot serve it, and `conversations`' own index
            | cannot either, because the student issues no `workspace_id` to enter
            | it by.
            */
            $table->index(['user_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};
