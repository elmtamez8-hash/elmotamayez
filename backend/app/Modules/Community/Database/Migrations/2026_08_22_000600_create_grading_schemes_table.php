<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's weighting of the four grade components (FR-049 … FR-051).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_schemes', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
            | ⚠️ `NOT NULL` WITH A SENTINEL, AND THE DATA MODEL SAYS `nullable`.
            | The deviation is deliberate: this column enters a unique key, and
            | NULL never equals NULL on either engine — so a nullable "the whole
            | workspace" scheme does not collide with itself, two of them insert
            | for one period, and the build picks whichever comes back first. It
            | is `concept_stats.lesson_id` and `unlock_rules.course_id` for the
            | third time in this repository, and the fix is the same one: 0 means
            | "every course in the workspace".
            |
            | The price of the sentinel is that `(int) null === 0`, so a failed
            | uuid resolve addresses the DEFAULT row — `SaveGradingScheme` guards
            | that before it writes, exactly as `UnlockRuleController` does.
            */
            $table->unsignedBigInteger('course_id')->default(0);

            $table->date('period_start');
            $table->date('period_end');

            // The four components and their percentages. A snapshot of this is
            // copied onto every segment at build time (FR-052), so editing this
            // row never reaches a card that was already published.
            $table->json('weights');

            $table->timestamps();

            $table->unique(
                ['workspace_id', 'course_id', 'period_start', 'period_end'],
                'grading_schemes_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_schemes');
    }
};
