<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's periodic assessment of one student (010 · FR-028 … FR-029).
 *
 * A BRIDGE row in the constitution's sense: it carries `workspace_id` for context
 * and points at the PLATFORM user on both sides. The teacher is stored as a user
 * id rather than a `teacher_profile_id` because an assistant may write one on the
 * teacher's behalf, and the author of an assessment is a person — not a
 * marketplace listing.
 *
 * ⚠️ `published_at` IS NULLABLE AND CLAIMED BY A CONDITIONAL UPDATE. A draft is a
 * row nobody but its author may read, and publishing is what tells the student and
 * their guardian. Written as `$review->published_at = now()` after a read, two
 * clicks send the notification twice — the seat idiom (`captured_order_id`,
 * `StructureVersion::claim()`) is the guard, and never `lockForUpdate()`, a no-op
 * on SQLite.
 *
 * The four axes are `unsignedTinyInteger` 1–5, enforced in the Action rather than
 * only in the FormRequest: the seeders and Filament reach the same Action with no
 * request behind them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodic_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('student_user_id');
            $table->unsignedBigInteger('teacher_user_id')->index();

            $table->date('period_start');
            $table->date('period_end');

            $table->unsignedTinyInteger('commitment');
            $table->unsignedTinyInteger('participation');
            $table->unsignedTinyInteger('homework');
            $table->unsignedTinyInteger('improvement');
            $table->text('note')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // One assessment per student per period per workspace (FR-032's
            // sibling). Two teachers in two workspaces each write their own.
            $table->unique(
                ['workspace_id', 'student_user_id', 'period_start', 'period_end'],
                'periodic_reviews_period_unique',
            );

            // The student's own screen: their rows, newest first, published only.
            $table->index(['student_user_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodic_reviews');
    }
};
