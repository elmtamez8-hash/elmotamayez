<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A study session — PLATFORM-owned (layer أ).
 *
 * ⚠️ THIS TABLE IS THE SOURCE OF TRUTH AND THERE IS NO CACHE KEY BESIDE IT.
 * Shared\Contracts\FocusState answers "is this student focusing?" by reading
 * `status = 'running'` here. A cache key was the first design, and every hazard
 * the notes warned about three times — a missed close leaving the mute stuck on
 * — existed ONLY because there would have been two copies of one fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('focus_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');

            /** Bounded in the FormRequest AND in the Action — unbounded, `100000` mutes every optional notification forever. */
            $table->unsignedSmallInteger('planned_minutes');

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            /** running · completed · interrupted. Decided by the SERVER, from the clock. */
            $table->string('status', 16)->default('running');

            $table->timestamps();

            /** The only question asked in the hot path: has this user a running one? */
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_sessions');
    }
};
