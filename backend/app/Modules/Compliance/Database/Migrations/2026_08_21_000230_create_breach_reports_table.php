<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reported breach and how far it has been handled (FR-040) — PLATFORM (layer ب).
 *
 * ⚠️ `reported_by_user_id` IS NULLABLE, AND THAT IS THE POINT OF THE WHOLE TABLE.
 * The requirement asks for a PUBLISHED route, which means an outside security
 * researcher uses it — and the best-known leaks are reported by people who are
 * not users. A NOT NULL column here would restrict the report to the population
 * least likely to be making it.
 *
 * ⚠️ AND THE SCOPE FIELDS ARE NOT IN THE PUBLIC REQUEST. Which categories, and how
 * many people — those are filled in by staff during triage. Accepting them from
 * the reporter would let anyone assert the size of an incident into our own record
 * of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breach_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Whatever an outside reporter left so we can answer them.
            $table->string('reporter_contact')->nullable();
            $table->text('description');

            $table->json('affected_categories')->nullable();
            $table->unsignedInteger('affected_subject_count')->nullable();

            $table->enum('status', ['reported', 'triaged', 'contained', 'notified', 'closed'])->default('reported');

            $table->timestamp('authority_notified_at')->nullable();
            $table->timestamp('subjects_notified_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breach_reports');
    }
};
