<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (recipient, event), however many channels carry it (FR-007).
 *
 * This replaces Laravel's own `notifications` table. That one keys on a bare
 * uuid with no autoincrement id, stores a PHP class name in `type`, and is
 * polymorphic on `notifiable` — three things this codebase does not do. More
 * decisively, FR-007 (one record) and FR-038 (one record per channel attempt)
 * need two tables, and Laravel's models only the first.
 *
 * Ownership layer: BRIDGE. The guard is recipient_user_id — the row belongs to a
 * person, not an academy. workspace_id is nullable context used to *filter* a
 * feed, never to scope it: a parent following one child across four teachers has
 * to see one stream (FR-025ب), and a mandatory workspace scope would make them
 * hunt through four.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();

            $table->string('type', 64);

            // Which student the event is about. A parent of three children needs
            // to know which one this concerns; null for platform-level events.
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Template variables only. The rendered text lives in title_ar/body_ar
            // so that editing a template tomorrow does not rewrite the archive.
            $table->json('payload')->nullable();

            $table->string('title_ar', 200);
            $table->text('body_ar');
            $table->string('action_url', 500)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Serves the unread count and the first page from one index (SC-008).
            $table->index(['recipient_user_id', 'read_at', 'id']);
            $table->index(['recipient_user_id', 'created_at']);
            $table->index('workspace_id');
            // Pruning scans by age (FR-017).
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
