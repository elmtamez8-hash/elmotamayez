<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The reminder's mark moves from the SESSION to the SEAT.
|
| ⛔ ONE MARK PER SESSION LOST TWO KINDS OF STUDENT. The sweep stamped the session
| the first time it saw it — even with nobody booked yet — so:
|   · a student who booked inside the last hour was never reminded, and if nobody
|     had booked by the first pass, nobody in that lesson ever was;
|   · a rescheduled lesson kept its stamp, so nobody was reminded before the NEW time.
| Per seat, a late booking is simply an unmarked row the next pass picks up, and a
| reschedule clears the marks of one session's seats.
|
| ⚠️ BACKFILLED BEFORE THE OLD COLUMN GOES, or every seat in a lesson starting
| within the hour of the deploy is reminded a second time.
|
| ⚠️ TWO `Schema::table` CLOSURES: SQLite rebuilds the table for a multi-alteration,
| and this repository keeps each alteration its own statement.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_bookings', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable();
        });

        // A correlated subquery on ANOTHER table — portable on MySQL and SQLite
        // (ERROR 1093 is only for a subquery on the table being updated).
        DB::table('session_bookings')->update([
            'reminded_at' => DB::raw('(select class_sessions.reminded_at from class_sessions where class_sessions.id = session_bookings.class_session_id)'),
        ]);

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }

    /*
    | ⚠️ The old column comes back EMPTY: which session a stamp belonged to is
    | derivable, but a partly-reminded session is not a state the old shape can
    | say, so nothing is copied back rather than something wrong.
    */
    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('seats_frozen_at');
        });

        Schema::table('session_bookings', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
