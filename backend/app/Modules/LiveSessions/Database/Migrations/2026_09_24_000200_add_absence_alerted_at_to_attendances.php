<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The instant absence alert's claim (`attendance_alert`, 2026-09-24).
|
| One per student per session, and the column IS the guard: the alert is sent
| only after a conditional `UPDATE … WHERE absence_alerted_at IS NULL` wins, so
| a redelivered listener finds nothing left to claim. Its own column rather than
| `report_sent_at`, because the report and the alert are two messages on two
| clocks — the report waits for the teacher's remarks, the alert does not.
|
| No backfill: every register closed before this column existed is left NULL,
| and nothing re-reads a closed register, so no old absence is alerted late.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->timestamp('absence_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('absence_alerted_at');
        });
    }
};
