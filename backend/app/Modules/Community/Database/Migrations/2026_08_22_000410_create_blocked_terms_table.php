<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-teacher term list — WORKSPACE-owned (layer 2).
 *
 * One row, one term, one policy: a slur is refused, a phone number is masked, a
 * borderline word is delivered and queued for a human (`TermPolicy`). Collapsing
 * the three into a single "block" is how a filter earns the reputation that makes
 * a room route around it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_terms', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');

            $table->string('term', 120);
            $table->string('policy', 20);

            $table->timestamps();

            $table->unique(['workspace_id', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_terms');
    }
};
