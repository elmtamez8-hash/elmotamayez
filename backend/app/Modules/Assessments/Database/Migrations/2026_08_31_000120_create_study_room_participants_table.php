<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 012 · T086 — one person inside one room, and where they got to.
 *
 * ⚠️ THE RESUME IS A ROW READ, NOT A BROWSER'S MEMORY (FR-015 · SC-007).
 * Whoever drops out and comes back reads `answered_count` and carries on, and
 * their answers are already in `exam_answers` under their own `attempt_id`. A
 * client-side resume loses the paper with the tab.
 *
 * ⚠️ ONE PRACTICE ATTEMPT PER PARTICIPANT — `is_practice = true`, `exam_id`
 * null. FR-018 is then satisfied by exactly the mechanism that protects the
 * adaptive session, rather than by a second one somebody has to remember.
 *
 * ⚠️ `index(user_id, joined_at)` IS NOT REDUNDANT WITH THE UNIQUE ABOVE IT. A
 * student is a member of no workspace, so no index on `study_rooms` serves «my
 * rooms»; and a composite index is only usable from its leading column, so
 * `unique(study_room_id, user_id)` cannot answer a query keyed on the user.
 * Without this line every page load of that list is a full table scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_room_participants', function (Blueprint $table): void {
            $table->id();
            /*
            | ⚠️ THIS is the identifier the live board carries, and the user's
            | uuid never is. A user uuid is that person's name on the platform
            | as a whole, handed to peers who may be children; this one dies
            | with the room.
            */
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('study_room_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('attempt_id');

            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedTinyInteger('answered_count')->default(0);

            $table->timestamp('joined_at');
            // Stamped by the answer that COMPLETES the set, and by nothing else:
            // a derived state fires no event, so hanging the gamification award
            // on `ends_at` passing would be a key with readers and no writer.
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->unique(['study_room_id', 'user_id']);
            $table->index(['user_id', 'joined_at']);
            // The board is one ordered read.
            $table->index(['study_room_id', 'score']);

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_room_participants');
    }
};
