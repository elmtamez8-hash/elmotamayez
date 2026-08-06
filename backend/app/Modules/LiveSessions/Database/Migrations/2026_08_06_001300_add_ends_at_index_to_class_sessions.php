<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            // Two queries filter on `ends_at` with no workspace to narrow them
            // first, so none of the three declared indexes applies to either:
            //
            //  - the student's timetable (`ends_at >= now()` AND status IN …),
            //    which crosses workspaces by design and runs on every page load
            //    of /schedule;
            //  - CloseStaleSessionsJob, hourly, across every workspace.
            //
            // Status leads because it is the selective half: live and
            // interrupted sessions are a handful at any moment, while "ends in
            // the future" is most of the table once the calendar fills up.
            $table->index(['status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'ends_at']);
        });
    }
};
