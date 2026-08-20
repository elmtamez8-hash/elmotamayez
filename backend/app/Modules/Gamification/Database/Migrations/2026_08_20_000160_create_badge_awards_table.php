<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Badges a student has earned — PLATFORM-owned (layer أ), like the progress file.
 *
 * "Awarded once and never twice" (FR-017) is the UNIQUE INDEX below and not a
 * check in the code: the evaluator runs from a queued job that can be replayed,
 * and a read-then-write check would be the same race the daily cap has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badge_awards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');

            /** Text, not an FK — a retired badge stays visible on the profile. */
            $table->string('badge_key', 64);

            $table->timestamp('awarded_at');
            $table->timestamps();

            $table->unique(['user_id', 'badge_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_awards');
    }
};
