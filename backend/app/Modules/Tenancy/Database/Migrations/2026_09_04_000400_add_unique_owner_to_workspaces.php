<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 025 · FR-008 — one owner, one workspace, enforced by the database.
 *
 * ⚠️ A UNIQUE INDEX ON A NULLABLE COLUMN, WHICH IS THIS REPOSITORY'S OWN IDIOM
 * AND NOT A MISTAKE. `payments.captured_order_id` is exactly the same shape, for
 * exactly the same reason: NULL never equals NULL, so every unclaimed row
 * coexists freely and the index bites only on a repeated non-null value. Nobody
 * needs it to bite on the orphan workspace — that row is deleted two migrations
 * earlier — and what is needed is that a second workspace for one owner cannot be
 * written, by anybody, from any surface.
 *
 * ⚠️ AND THE INDEX IS NOT THE WHOLE GUARD. `CreateWorkspace` refuses first, so a
 * person gets a sentence instead of a `QueryException`. The index is what makes
 * the refusal a fact rather than a check-then-write — the read-then-write race
 * this repository refuses everywhere else, and it has a real surface here: the
 * FR-009 panel screen double-clicked, or an administrator creating for a teacher
 * while the backfill migration creates for the same teacher.
 *
 * ⚠️ IT RUNS AFTER THE BACKFILL (`..._000100`) BY FILENAME, because the backfill
 * creates owners. Measured on production the day this shipped: zero owners hold
 * two workspaces, so no data has to move first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->unique('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropUnique(['owner_user_id']);
        });
    }
};
