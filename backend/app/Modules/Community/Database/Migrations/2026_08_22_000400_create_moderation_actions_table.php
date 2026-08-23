<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every moderation decision — WORKSPACE-owned (layer 2).
 *
 * ⚠️ APPEND-ONLY, AND LIFTING A BAN IS A NEW ROW. This table IS the record
 * `FR-021` asks for: who acted, on whom, and why. Deleting the ban row when it is
 * lifted erases the fact that somebody was banned and the reason given — and «is
 * this person banned right now» is answered by the most recent row winning, never
 * by a row's existence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_actions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');

            // Nullable: a moderator is a person and people leave, and the record
            // of the decision must outlive them.
            $table->unsignedBigInteger('actor_user_id')->nullable();

            // `user` or `message`. Two shapes in one table because they share
            // every read — the audit list and «is this person banned».
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');

            $table->string('verdict', 20);
            $table->text('reason')->nullable();

            /*
            | ⚠️ NULL MEANS PERMANENT, AND IT IS READ WITH A GROUPED PREDICATE:
            | `(expires_at IS NULL OR expires_at > now())`. Ungrouped, `NULL >
            | now()` is NULL and the permanent ban ends one second after it is
            | made — the same shape as 013's legal-hold `whereNotIn` on a nullable
            | column.
            */
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            /*
            | ⚠️ NOT `(workspace_id, created_at)`. The hot read is «is this subject
            | under this verdict right now», which names no date at all — so a
            | date-tailed index degrades to one leading column and scans the whole
            | moderation history on EVERY message sent.
            */
            $table->index(['workspace_id', 'subject_type', 'subject_id', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_actions');
    }
};
