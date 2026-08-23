<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One room per session and one per lesson.
 *
 * ⚠️ THE PUBLIC KINDS HAD NO UNIQUENESS GUARD AT ALL, and `unique(workspace_id,
 * student_user_id)` cannot supply one: `student_user_id` is NULL for every room,
 * and NULL never collides with NULL — which is exactly what lets rooms coexist
 * freely and is exactly why they need their own. Without these, two students
 * opening the same class chat in the same second both find nothing and both
 * insert: two conversations for one session, the room split in half for ever,
 * with nothing that throws and no single-threaded test that can see it.
 *
 * Single-column and nullable, so a private conversation — null on both — collides
 * with nothing. With them, `ResolveSessionConversation` can use the declared
 * loser path: catch the violation, re-read, return the winner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unique('class_session_id');
            $table->unique('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropUnique(['class_session_id']);
            $table->dropUnique(['lesson_id']);
        });
    }
};
