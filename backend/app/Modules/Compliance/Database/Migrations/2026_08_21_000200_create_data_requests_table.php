<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A request to see, export or erase one person's data — PLATFORM (layer أ).
 *
 * ⚠️ NO `BelongsToWorkspace`, AND ADDING ONE WOULD BREAK THE RIGHT ITSELF. One
 * request covers the subject's data at EVERY teacher they study with; a tenant
 * column would split it into one request per workspace, and a partial export is
 * not the right that was implemented. The guard is row ownership — the subject,
 * an authorised guardian, or a platform permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Who it is ABOUT and who ASKED are different people whenever a
            // guardian acts, and conflating them is how a request gets fulfilled
            // to the wrong inbox.
            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('type', ['access', 'export', 'erasure']);
            $table->enum('status', ['pending', 'processing', 'completed', 'refused', 'on_hold'])->default('pending');

            /*
            | ⚠️ ONE OPEN REQUEST PER (SUBJECT, TYPE) — ENFORCED BY A UNIQUE
            | NULLABLE COLUMN, NEVER BY A PARTIAL INDEX.
            |
            | "Read, then insert" loses to two taps in one second, and a
            | per-minute throttle does not close a race measured in milliseconds.
            | The obvious form — a unique index with `WHERE status = 'pending'` —
            | is a POSTGRES feature; MySQL has no partial indexes, so it silently
            | does not exist on the database this ships to.
            |
            | So: written `"{subject}:{type}"` on creation, nulled on close, and
            | NULL never collides with NULL. Precedent, identical in shape:
            | `payment_transactions.captured_order_id`.
            |
            | And it is deliberately NOT `$fillable` — mass-assignable, it becomes
            | a second way to claim or release the lock from outside the statement
            | that owns it.
            */
            $table->string('open_key', 80)->nullable()->unique();

            // The legal answering deadline (FR-043), derived from platform
            // settings at creation.
            $table->timestamp('due_at');

            /*
            | Stamped inside the SAME statement that claims the request. A
            | separate write is a second round trip in which a competing worker
            | claims it too, and `updated_at` cannot serve — it moves for every
            | unrelated write to the row. Precedent: `recording_attempted_at`.
            */
            $table->timestamp('last_attempt_at')->nullable();

            // Where the archive landed, and when the signed link stops working.
            $table->string('export_path')->nullable();
            $table->timestamp('export_expires_at')->nullable();

            // FR-026 — who executed it and, if refused, why.
            $table->foreignId('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('refusal_reason')->nullable();

            /*
            | What the REQUESTER is entitled to see, when they are not the
            | subject. A guardian granted "attendance" alone must not receive
            | results, payments or recordings — the permission limits the
            | CONTENT, not merely the door.
            */
            $table->json('granted_scope')->nullable();

            $table->timestamps();

            $table->index(['subject_user_id', 'type', 'status']);
            // The officer's queue, and "what is late" — `due_at` is the legal
            // deadline and had no index to be asked by.
            $table->index(['status', 'due_at']);
            // The stalled sweep.
            $table->index(['status', 'last_attempt_at']);
            // The file cleaner.
            $table->index('export_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
    }
};
