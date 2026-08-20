<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per sweep (FR-031) — PLATFORM reference data (layer ب).
 *
 * ⚠️ THE REQUIREMENT ASKED FOR A LOG OF EVERY RUN AND THERE WAS NO TABLE FOR IT,
 * while the plan simultaneously warned against displaying a column that does not
 * exist. Shaped after `credit_reconciliation_runs`.
 *
 * ⚠️ AND `findings_count` IS SEPARATE FROM `findings` FOR ONE REASON, written
 * down where that table wrote it: so a run that FOUND NOTHING can be told apart
 * from a run that DID NOT LOOK. The json is capped; the count stays honest
 * whatever was truncated out of it.
 *
 * ⚠️ AND THE IDEMPOTENCY INVARIANT FOR THIS TABLE IS THE OPPOSITE OF THE DATA'S.
 * SC-010 says two runs leave the same state — true of the rows being swept, FALSE
 * here, because this log is append-only by nature. The correct triple is:
 * identical data rows · EXACTLY ONE run row per execution · zero destructive
 * effect on the second pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_sweep_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->timestamp('ran_at')->index();

            $table->unsignedInteger('categories_processed')->default(0);
            $table->unsignedInteger('rows_deleted')->default(0);
            $table->unsignedInteger('rows_anonymised')->default(0);
            $table->unsignedInteger('rows_archived')->default(0);

            // Capped in the Job. A findings blob that grows with the failure is
            // how one bad night fills the disk.
            $table->json('findings')->nullable();
            $table->unsignedInteger('findings_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_sweep_runs');
    }
};
