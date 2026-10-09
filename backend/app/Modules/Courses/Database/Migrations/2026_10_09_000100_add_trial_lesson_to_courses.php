<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 040 — «الحصة التجريبية»: one recorded lesson per course that any visitor
| may watch (owner decisions 2026-10-09).
|
| ONE COLUMN, so «one trial per course» holds by construction: a second pick is
| an UPDATE that replaces the first, with no count and no lock (research R1).
|
| `nullOnDelete`: deleting the lesson (hard — `Lesson` has no SoftDeletes) drops
| the mark with it. A soft-deleted COURSE keeps it, and the public reads hide it
| because the course is no longer listed; restoring the course restores the trial.
|
| ⚠️ NOT added to `$fillable`. The column has ONE writer, `SetCourseTrialLesson`,
| which `forceFill`s it after `TrialLessonRule` — a mass assignment anywhere else
| would skip that rule.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('trial_lesson_id')
                ->nullable()
                ->after('promo_video_reviewed_by')
                ->constrained('lessons', indexName: 'courses_trial_lesson_id_foreign')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // The foreign key in its own statement first: SQLite's DROP COLUMN
        // refuses a column that still carries one (see add_promo_video_to_courses).
        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_lesson_id');
        });
    }
};
