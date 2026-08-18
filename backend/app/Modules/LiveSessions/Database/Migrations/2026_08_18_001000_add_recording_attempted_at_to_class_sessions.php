<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the recording was last asked about — the clock the attempt budget assumed.
 *
 * ⚠️ `recording_attempts` IS A BUDGET OF FIVE THAT EVERYONE READ AS «AN HOUR AND A
 * QUARTER», AND NOTHING MADE THAT TRUE. The sweep runs every fifteen minutes, so
 * five attempts *look* like 75 minutes of patience — but the only guard was the
 * schedule itself. Measured on 2026-08-18: a queue worker started before a fix and
 * restarted after it left 72 sweep passes queued, they drained in one second, and
 * five attempts were spent inside that second. The session was written `failed`, the
 * teacher told the recording was lost, and the seat holders told the same — about a
 * file sitting intact in our own bucket, mid-transcode, which appeared minutes later.
 *
 * A backlog is not a local curiosity: a paused Horizon supervisor, a deploy, or any
 * queue outage produces the identical pile.
 *
 * ⚠️ AND `updated_at` WAS NOT USABLE FOR THIS, WHICH IS WHY THERE IS A COLUMN. It
 * moves for every unrelated write to the row, so a session touched by anything else
 * becomes eligible or ineligible by accident — the same reason the dormancy notice
 * has `notified_dormant_at` rather than a predicate on the ledger's timestamp.
 * Nullable with no default: a session that has never been asked about must be swept
 * immediately, and `null` is the honest spelling of that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->timestamp('recording_attempted_at')->nullable()->after('recording_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->dropColumn('recording_attempted_at');
        });
    }
};
