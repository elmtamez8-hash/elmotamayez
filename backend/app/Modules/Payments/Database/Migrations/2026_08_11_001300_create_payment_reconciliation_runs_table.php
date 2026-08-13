<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each pass of the payment reconciliation looked at, and what it found.
 *
 * Platform-owned, kind (ب) of the constitution's three layers: the sweep spans
 * every workspace by construction, so there is no `workspace_id` and no global
 * scope. The guard is the platform permission on the single route that reads it
 * — and `PaymentReconciliationAccessTest` asserts the holder of the highest
 * TENANT role is refused, which is what "platform-owned" has to mean in practice
 * rather than in a comment.
 *
 * ⚠️ THE WINDOW IS STORED, NOT DERIVED FROM `ran_at`. The next pass starts
 * exactly where this one stopped, so `window_to` is the input to the run after
 * it; deriving it from "an hour before the run" would leave a gap whenever a run
 * was late and would double-scan whenever one was early. Half-open `[from, to)`,
 * so the boundary second is visited once — closed at both ends visits it twice
 * and open at both drops it.
 *
 * ⚠️ AND `unresolved_count` IS THE TRUE TOTAL WHILE `findings` IS A SAMPLE. A cap
 * that reports itself as everything is how a broken deploy reads as three
 * problems instead of nine thousand; the job logs when it truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->timestamp('ran_at')->index();

            // The half-open window this pass covered. Its `window_to` is the next
            // pass's `window_from`, which is the whole reason it is a column.
            $table->timestamp('window_from');
            $table->timestamp('window_to');

            // Looked at, put right, and left for a human — three numbers because
            // "found nothing" and "looked at nothing" are otherwise one answer.
            $table->unsignedInteger('checked_count')->default(0);
            $table->unsignedInteger('corrected_count')->default(0);
            $table->unsignedInteger('unresolved_count')->default(0);

            $table->json('findings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_runs');
    }
};
