<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One student's cumulative report across every teacher they study with.
 *
 * ⚠️ NO `workspace_id`, BY DESIGN. The constitution lists this table by name
 * among the platform-owned entities: a student has ONE record of their term, not
 * one per teacher. Adding `BelongsToWorkspace` here would silently duplicate one
 * person per teacher — the mirror-image bug `PlatformOwnershipTest` exists to
 * catch in both directions — and it would make FR-041's "each teacher's
 * contribution shown separately" impossible, because there would be nothing left
 * to separate.
 *
 * ⚠️ AND NO `file_path` COLUMN. The rendered PDF goes through medialibrary, the
 * mechanism `Certificate` already uses. A raw path column escapes the provider
 * resolver, the 013 retention sweep and FR-036's floor — which in this table
 * means a PDF carrying a minor's grades that nothing on the platform ever
 * deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_cards', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('student_user_id')->constrained('users')->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            $table->timestamp('generated_at');

            /*
            | ⚠️ BOTH NULLABLE UNTIL THE PUBLISH CLAIM WRITES THEM. The totals are
            | computed inside the conditional UPDATE that publishes the card and
            | at no other moment: a total accumulated as each segment is written
            | interleaves between two teachers building at once and produces a
            | number that matches no set of segments at all — permanently, since
            | nothing ever recomputes it.
            |
            | `improvement_index` stays null when no teacher rated improvement in
            | the period. Zero would read as "did not improve", which is a
            | judgement nobody made (FR-053's rule, reached from a second side).
            */
            $table->decimal('overall_pct', 5, 2)->nullable();
            $table->decimal('improvement_index', 5, 2)->nullable();

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['student_user_id', 'period_start', 'period_end'],
                'report_cards_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_cards');
    }
};
