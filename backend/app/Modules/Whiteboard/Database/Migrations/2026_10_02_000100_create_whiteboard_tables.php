<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 039 — the teacher's whiteboard: boards, their pages, and the link from a
 * board to the lesson attachment it was exported as. (A fourth table, `board_imports`,
 * was dropped on 2026-10-03: PDFs are read in the browser — `_000100_drop_board_imports_table`.)
 *
 * All three are WORKSPACE-OWNED (constitution I): `BelongsToWorkspace` on every
 * model and a row each in `WorkspaceIsolationTest`.
 *
 * ⚠️ EVERY FOREIGN KEY TO `media_assets` IS `nullOnDelete`. Media deletes its own
 * rows (`DeleteMediaAsset`, and `ManageLessons` before it deletes a lesson); a
 * RESTRICT key here would turn deleting a lesson that carries an exported board
 * into a 500, and SQLite — which enforces foreign keys in the suite — is the only
 * place that would show it before production did.
 *
 * ⚠️ `editor_seen_at` / `editor_handover_at` CARRY MILLISECONDS
 * (`timestamp(3)`), and `BoardLock` writes them as formatted STRINGS. Laravel
 * formats a bound date object with the grammar's `Y-m-d H:i:s`, dropping the
 * fraction — and on MySQL an UPDATE that writes the value already stored reports
 * zero affected rows, so a heartbeat in the same second as the last one would read
 * as a lost lock.
 *
 * ⚠️ STATUSES ARE STRINGS, NOT ENUM COLUMNS — the tree's rule (`media_assets.status`):
 * a value added in code must not need a migration to be storable.
 *
 * ⚠️ `pages_count` IS UNSIGNED, so `BoardPageGate` never subtracts below zero in
 * SQL (`WHERE pages_count >= ?`), and positions are parked ABOVE the maximum,
 * never at a negative value: MySQL strict refuses both with ERROR 1690 / 1264 and
 * SQLite stores them silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160);
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('class_session_id')->nullable()->constrained('class_sessions')->nullOnDelete();
            $table->string('background', 16)->default('white');

            // The edit lock (research R-09). One editor at a time.
            $table->foreignId('editor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('editor_tab_id')->nullable();
            $table->timestamp('editor_seen_at', 3)->nullable();
            $table->uuid('editor_handover_tab')->nullable();
            $table->timestamp('editor_handover_at', 3)->nullable();

            // Written only inside the row-gated transactions (BoardPageGate).
            $table->unsignedSmallInteger('pages_count')->default(0);
            // building · duplicating · deleting — a double-click guard for the queued jobs.
            $table->string('pending_operation', 16)->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'owner_user_id']);
            $table->index(['workspace_id', 'course_id']);
            $table->index(['workspace_id', 'lesson_id']);
            $table->index(['workspace_id', 'class_session_id']);
        });

        Schema::create('board_pages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->foreignId('board_id')->constrained('boards')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            // longText, not json: MySQL's JSON type re-parses and reorders keys, so
            // the stored bytes would no longer be the bytes `scene_bytes` counted.
            $table->longText('scene');
            $table->unsignedInteger('scene_bytes');
            $table->unsignedInteger('version')->default(1);

            // The idempotency key of a retried save is (client_tab, client_rev).
            $table->uuid('client_tab')->nullable();
            $table->unsignedInteger('client_rev')->nullable();
            $table->foreignId('background_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->timestamps();

            $table->unique(['board_id', 'position']);
            $table->index('workspace_id');
        });

        Schema::create('board_lesson_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->foreignId('board_id')->constrained('boards')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->timestamps();

            $table->unique(['board_id', 'lesson_id']);
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_lesson_exports');
        Schema::dropIfExists('board_pages');
        Schema::dropIfExists('boards');
    }
};
