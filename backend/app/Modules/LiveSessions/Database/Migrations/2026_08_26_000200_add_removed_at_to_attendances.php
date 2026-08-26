<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «أخرجه المدرّس» — and until this column existed, that lasted one refresh.
     *
     * ⚠️ REMOVING SOMEBODY WAS A PROVIDER CALL AND NOTHING ELSE. The participant
     * was disconnected; the room stayed open, the seat stayed booked, and
     * `IssueJoinTicket` happily minted a fresh ticket to the same person a second
     * later — so the teacher's one control over a disruptive student was a
     * button that inconvenienced them for as long as it took to press F5.
     * Reported from a real lesson on 2026-08-26.
     *
     * ⚠️ IT LIVES ON `attendances` AND NOT ON THE BOOKING. Cancelling the seat
     * would repossess a session the student PAID for over a moment's behaviour,
     * and the money half of that is a refund nobody asked for. This says «you are
     * out of THIS hour», which is exactly what the teacher meant.
     *
     * A timestamp, for the reason `conversations.locked_at` is one: it is also
     * the record of WHEN, which is what makes it arguable afterwards. Nullable
     * and cleared by readmission, because a removal pressed in error must be
     * undoable — the alternative is a teacher who cannot let a student back into
     * the lesson they are paying for.
     *
     * Not indexed: read through an attendance row already resolved by
     * `(class_session_id, student_user_id)`, which has a unique index.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
