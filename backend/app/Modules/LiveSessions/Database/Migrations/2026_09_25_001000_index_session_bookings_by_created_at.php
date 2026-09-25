<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `session_bookings (created_at)` — for the nightly retention sweep.
 *
 * `LiveSessionsPersonalData` clears `cancellation_reason` with
 * `WHERE created_at < ? AND cancellation_reason IS NOT NULL LIMIT n` across the
 * whole platform. The existing `(student_user_id, created_at)` cannot serve a
 * predicate with no student in it, so every night the sweep read every seat ever
 * booked — the fastest-growing table in the module — to find the handful past
 * the retention period.
 *
 * Hand-named, 33 characters: MySQL refuses an identifier over 64 and SQLite has
 * no limit at all, so only the deploy would say.
 */
return new class extends Migration
{
    private const INDEX = 'session_bookings_created_at_index';

    public function up(): void
    {
        if (Schema::hasIndex('session_bookings', self::INDEX)) {
            return;
        }

        Schema::table('session_bookings', function (Blueprint $table): void {
            $table->index('created_at', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('session_bookings', self::INDEX)) {
            return;
        }

        Schema::table('session_bookings', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
    }
};
