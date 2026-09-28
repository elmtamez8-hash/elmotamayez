<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a message in a private thread came from the TEACHER'S side (2026-09-28).
 *
 * The prospect cap lifts when the teacher's side answers AFTER the student
 * became a prospect (`ProspectAllowance`), so each line has to say which side
 * wrote it — decided by the policy at send time, never re-derived: a workspace
 * membership is not the answer (a student a teacher added carries a `student`
 * pivot row) and a guardian is a member of nothing.
 *
 * ⚠️ THE BACKFILL MARKS OLD LINES TRUTHFULLY AND UNLOCKS NOBODY. Before this date
 * only the student and the teacher's side could write, so «not the student» is
 * «the teacher's side». Every one of those replies was written while the student
 * was enrolled — the old policy refused both sides otherwise — so each predates
 * the moment a former student became a prospect, and the cap ignores it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('from_staff')->default(false)->after('sender_user_id');
        });

        DB::table('messages')
            ->whereIn('conversation_id', fn ($query) => $query->select('id')->from('conversations')->where('kind', 'private'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('conversations')
                ->whereColumn('conversations.id', 'messages.conversation_id')
                ->whereColumn('conversations.student_user_id', 'messages.sender_user_id'))
            ->update(['from_staff' => true]);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('from_staff');
        });
    }
};
