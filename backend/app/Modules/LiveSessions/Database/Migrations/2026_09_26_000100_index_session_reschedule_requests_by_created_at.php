<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `session_reschedule_requests (created_at)` — for the nightly retention sweep.
 *
 * `LiveSessionsPersonalData::expire()` clears `student_reason` with
 * `WHERE created_at < ? AND student_reason IS NOT NULL LIMIT n` across the whole
 * platform. No existing index leads with `created_at` (the table carries
 * `workspace_id`, `class_session_id`, `student_user_id`, `(workspace_id, status)`
 * and the pending-slot unique), so the sweep read every request ever made.
 *
 * `attendances` needs nothing: it has carried `(created_at)` since 2026_08_21.
 *
 * Hand-named, 44 characters: MySQL refuses an identifier over 64 and SQLite has
 * no limit at all, so only the deploy would say.
 */
return new class extends Migration
{
    private const INDEX = 'session_reschedule_requests_created_at_index';

    public function up(): void
    {
        if (Schema::hasIndex('session_reschedule_requests', self::INDEX)) {
            return;
        }

        Schema::table('session_reschedule_requests', function (Blueprint $table): void {
            $table->index('created_at', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('session_reschedule_requests', self::INDEX)) {
            return;
        }

        Schema::table('session_reschedule_requests', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
    }
};
