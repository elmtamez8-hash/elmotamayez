<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hold that stops an erasure — PLATFORM (layer أ).
 *
 * ⚠️ `released_at` IS THE WHOLE STATE, AND THERE IS NO `is_active` BESIDE IT. Two
 * columns answering one question diverge at the first write that touches one and
 * not the other — and here the divergence means an erasure proceeding against a
 * hold that a court placed. `whereNull('released_at')` is the only predicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');

            $table->foreignId('placed_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('placed_at');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['subject_user_id', 'released_at']);

            /*
            | ⚠️ THE SECOND INDEX IS FOR THE SWEEP, WHICH ASKS A DIFFERENT
            | QUESTION. It wants "every hold in force" ONCE per run, not "is this
            | person held" once per row — the first shape is a single scan, the
            | second is a query per subject inside a nightly loop.
            */
            $table->index('released_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_holds');
    }
};
