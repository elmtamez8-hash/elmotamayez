<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One thread — WORKSPACE-owned (layer 2).
 *
 * Three kinds share the table because they share every read: the same page of
 * fifty, the same ordering, the same hide. What differs is who is a party, and
 * that is a branch in the policy rather than a second schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');

            // `private` · `session` · `lesson` — see `ConversationKind`, which
            // also records why there is no `group` member.
            $table->string('kind', 20);

            $table->unsignedBigInteger('student_user_id')->nullable();
            $table->unsignedBigInteger('class_session_id')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();

            /*
            | The newest message, denormalised so the list screen can sort and
            | preview without touching `messages` per row.
            |
            | ⚠️ WRITTEN BY A CONDITIONAL UPDATE, never by assignment — see
            | `PostMessage::claimLastMessage()`. Two writers in the same
            | millisecond can otherwise land the SMALLER id last, and the
            | conversation is then sorted for ever by a message that is not its
            | most recent one.
            */
            $table->unsignedBigInteger('last_message_id')->nullable();

            $table->timestamps();

            /*
            | One private conversation per student per workspace.
            |
            | ⚠️ TWO PLAIN COLUMNS AND NO COMPUTED COLUMN. `NULL` never equals
            | `NULL` in a unique index on either engine, so every public
            | conversation — whose `student_user_id` is null — coexists freely
            | while the private one is genuinely one per student. The sentinel
            | trick `concept_stats.lesson_id` needed is the wrong shape here: a
            | zero would make ONE public conversation per workspace and refuse the
            | second lesson room anybody opened.
            */
            $table->unique(['workspace_id', 'student_user_id']);

            // The teacher's list: their workspace, newest first.
            $table->index(['workspace_id', 'last_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
