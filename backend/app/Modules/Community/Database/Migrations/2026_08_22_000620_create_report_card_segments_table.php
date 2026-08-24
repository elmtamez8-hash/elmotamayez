<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One teacher's contribution to one student's report card (FR-041).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_segments', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('report_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_user_id')->constrained('users')->cascadeOnDelete();

            /*
            | ⚠️ `student_user_id` IS DUPLICATED FROM THE CARD ON PURPOSE. The
            | constitution defines a bridge table as one that carries
            | `workspace_id` for context AND points at the platform-wide user;
            | without this column it is not a bridge at all but a platform detail
            | table with no owner, which is exactly the shape no scope and no
            | policy knows how to guard.
            */
            $table->foreignId('student_user_id')->constrained('users')->cascadeOnDelete();

            /*
            | The four components as they were computed, WITH the weights that
            | were in force (FR-052). A snapshot, not a reference: the teacher may
            | reweight tomorrow, and a published card must not silently become a
            | different document than the one the family already read.
            |
            | A component with no data in the period is absent from this list
            | entirely and the rest are re-weighted (FR-053) — never stored as
            | zero, which is the `wrong_pct = NULL` decision reached from a second
            | direction. A student with no homework assigned is not a student who
            | scored nothing.
            */
            $table->json('components');

            $table->decimal('attendance_pct', 5, 2)->nullable();
            $table->decimal('segment_pct', 5, 2)->nullable();

            $table->timestamps();

            $table->index('report_card_id');
            $table->unique(['report_card_id', 'workspace_id'], 'report_card_segments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_segments');
    }
};
