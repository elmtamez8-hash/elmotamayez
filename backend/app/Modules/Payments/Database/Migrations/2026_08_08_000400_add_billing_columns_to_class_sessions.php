<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns the charge path needs. `subject_id` already exists from 005.
 *
 * grade_level: copied from the course at scheduling time. Without it the two
 * sides resolve the rate from different inputs — and worse, AccrueTeachingUnits
 * passes no grade level at all today, so every grade-specific rate is invisible
 * at settlement. Storing it on the session is what makes both reads identical.
 *
 * charged_at: makes "delivered but never charged" a QUERYABLE set.
 *
 * That set is not hypothetical. CloseClassSession returns early on a terminal
 * status, so SessionDelivered is fired exactly once, ever — a queue outage or a
 * throw in an earlier synchronous listener leaves the session unbilled forever,
 * with no second chance. And because withholding is derived from the balance,
 * the student's record stays clean and they keep booking. Marking the moment is
 * what lets ChargeUnbilledDeliveriesJob find them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->string('grade_level', 32)->nullable()->after('subject_id');
            $table->timestamp('charged_at')->nullable()->after('seats_frozen_at');

            // The sweep's query: delivered sessions of this workspace with no
            // charge recorded. Leading with charged_at because it is the
            // selective side — almost every row is charged.
            $table->index(['charged_at', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->dropIndex(['charged_at', 'workspace_id']);
            $table->dropColumn(['grade_level', 'charged_at']);
        });
    }
};
