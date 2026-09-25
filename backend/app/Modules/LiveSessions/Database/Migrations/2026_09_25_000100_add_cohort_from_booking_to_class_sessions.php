<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a session's group was written by the SEAT rather than by a person.
 *
 * An open 1:1 slot generated from a teacher's availability has no group until
 * somebody books it; `BookSeat` then files it under that student's one-seat
 * group. Nothing took that group off again when the seat went, so a slot whose
 * first booker cancelled was refused to every other student for ever
 * (`mayHoldSeatIn`), hidden from discovery, and still counted as busy on the
 * teacher's calendar. `ClassSession::reopenEmptyIndividualSlot()` clears it —
 * and this column is what tells that slot apart from a 1:1 session a teacher
 * scheduled FOR one student (a granted private request), whose group must stay.
 *
 * ⚠️ FALSE FOR EVERY EXISTING ROW, ON PURPOSE. A slot already stamped before this
 * column existed cannot be told apart from a scheduled one after the fact, so it
 * keeps today's behaviour rather than being guessed open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->boolean('cohort_from_booking')->default(false)->after('cohort_id');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->dropColumn('cohort_from_booking');
        });
    }
};
