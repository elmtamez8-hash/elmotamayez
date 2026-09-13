<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ٠٣٥ · T005 — «هذا المقعدُ مخصومٌ»، مختوماً بوقتِه.
     *
     * A timestamp and not a boolean, because «when was this judged» is part
     * of the answer the day a charge is disputed a month later.
     *
     * Stamped ⇒ this seat is charged: either the stay reached the bar, or the
     * student fell short AND gave no notice (FR-008ج — the silent no-show pays,
     * because most lessons here are one-to-one and an empty seat costs the
     * teacher the whole hour). NULL ⇒ judged and exempt.
     *
     * ⚠️ IT IS THE CHARGE AND NOT THE STAY, deliberately. The billing side needs
     * a per-STUDENT answer and `class_sessions.charged_seats` is only a count —
     * so re-deriving the four exemptions over in Payments would be two spellings
     * of one question, over three tables that belong to this module. It is also
     * exactly what the content gate reads, because «charged» and «receives the
     * hour» are the same set (FR-008ج · FR-007).
     *
     * ⚠️ AND «NOT JUDGED YET» IS A QUESTION ABOUT THE SESSION, NEVER ABOUT THIS
     * ROW — `class_sessions.attended_seats IS NULL`. `scripts/deploy.sh`
     * raises the containers (line 27) BEFORE `migrate --force` (line 38),
     * and Eloquent returns null for a column that does not exist rather than
     * throwing. So the branch is read from the SESSION
     * (`class_sessions.attended_seats IS NULL` ⇒ fall back to the pre-035
     * rule, charge every seat), never from this column per row.
     *
     * ⚠️ AND IT IS NEVER DERIVED FROM `status` OR FROM `auto_status`: the
     * first is written by the teacher (FR-004) and the second is wrong in
     * both directions — «late» is the verdict on somebody who joined late
     * and stayed, AND on somebody who joined on time and left after two
     * minutes; 035 charges the first and not the second.
     *
     * Not indexed: read through an attendance row already resolved by
     * `(class_session_id, student_user_id)`.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->timestamp('credit_verdict_at')->nullable()->after('removed_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('credit_verdict_at');
        });
    }
};
