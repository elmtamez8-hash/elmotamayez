<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the teacher's side first answered in a private conversation (2026-09-28).
 *
 * The prospect cap — «at most N messages until somebody on the teacher's side
 * replies» — needs to know whether that reply has happened, and it needs to know
 * it under a row lock on THIS row. A stamp on the conversation answers it without
 * deciding, message by message, who each sender was: a workspace membership is
 * not the answer (a student a teacher added carries a `student` pivot row), and a
 * guardian is a member of nothing. `PostMessage` stamps it at write time, when
 * the policy has just said which side the sender is on.
 *
 * ⚠️ THE BACKFILL IS WHAT KEEPS EVERY EXISTING THREAD OPEN. Before today nobody
 * but the student and the teacher's side could write into a private thread, so
 * any message not sent by the student IS a staff reply. Without the backfill a
 * former student whose teacher answered them a hundred times would meet the cap
 * on their next message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('staff_replied_at')->nullable()->after('locked_at');
        });

        $firsts = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.kind', 'private')
            ->whereColumn('messages.sender_user_id', '!=', 'conversations.student_user_id')
            ->groupBy('messages.conversation_id')
            ->select('messages.conversation_id')
            ->selectRaw('MIN(messages.created_at) as first_reply_at')
            ->get();

        foreach ($firsts as $row) {
            DB::table('conversations')
                ->where('id', $row->conversation_id)
                ->whereNull('staff_replied_at')
                ->update(['staff_replied_at' => $row->first_reply_at ?? now()->format('Y-m-d H:i:s')]);
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('staff_replied_at');
        });
    }
};
