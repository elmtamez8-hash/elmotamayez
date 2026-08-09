<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this course last delivered a session — the stop-selling signal (FR-021ط).
 *
 * ⚠️ IT LIVES HERE, ON A COLUMN BILLING OWNS, AND THAT IS THE WHOLE DESIGN.
 *
 * "Has this teacher stopped delivering?" is answerable from the settlement side,
 * where delivered teaching units are already counted — and reading that from
 * Payments is exactly what ContextIsolationTest fails the build over, in the
 * direction it was written to catch. The one sanctioned bridge between the two
 * contexts is the SessionDelivered event, so this column is stamped by a
 * Payments listener on that event and by nothing else.
 *
 * Nullable, and null does NOT mean stalled: a course that has never delivered
 * anything is new, not abandoned. StopSellingGuard falls back to `created_at`,
 * so the clock starts the day the course exists and a course sells from its
 * first hour.
 *
 * On `courses` rather than a table of its own for the same reason the pricing
 * keys are: one course has one answer, and a join table for one nullable
 * timestamp is a join on every packages request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->timestamp('last_delivered_at')->nullable()->after('teacher_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('last_delivered_at');
        });
    }
};
