<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A teacher winding down their workspace — BRIDGE, and it DOES carry
 * `workspace_id` (constitution v1.2.0 §I).
 *
 * ⚠️ THE CLASSIFICATION IS DECLARED, NOT INFERRED, and an earlier draft of the
 * plan got it wrong by not declaring it — writing "no workspace-owned model is
 * added, so no isolation case is needed", which is false. The thing being wound
 * down IS a workspace, so the request is about one. It uses `BelongsToWorkspace`
 * like the two shipped bridges (`Enrollment`, `SessionBooking`), and a case goes
 * into `WorkspaceIsolationTest` in the same commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_offboardings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('teacher_user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('status', ['requested', 'settlement_pending', 'notice_period', 'completed'])->default('requested');

            /*
            | ⚠️ COMPLETION IS FORBIDDEN WHILE THIS IS NULL (FR-032), and the check
            | is a CONDITIONAL UPDATE rather than a read followed by a write: two
            | operators pressing complete both pass a read, both write, and the
            | FR-037 side effects — memberships ended, assistant permissions
            | revoked, tokens killed — are not reversible.
            */
            $table->timestamp('settlement_cleared_at')->nullable();

            $table->timestamp('students_notified_at')->nullable();
            $table->timestamp('notice_ends_at')->nullable();

            // FR-034 — where the teacher's own content archive was written.
            $table->string('content_export_path')->nullable();

            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('workspace_id');
            // "Which offboarding is waiting on settlement" — the officer's queue.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_offboardings');
    }
};
