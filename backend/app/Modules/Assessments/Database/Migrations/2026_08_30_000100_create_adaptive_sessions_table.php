<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 012 · T017 — one student practising one concept, one question at a time.
 *
 * A BRIDGE in the constitution's three layers: the concept and the questions are
 * the teacher's, the student is the platform's, so the row carries
 * `workspace_id` for CONTEXT and points at the platform-wide user — exactly what
 * `enrollments` does.
 *
 * ⚠️ AND `BelongsToWorkspace` GUARDS ALMOST NOTHING ON THE PATH THAT REACHES
 * THIS TABLE. `WorkspaceScope::apply()` adds no condition when
 * `WorkspaceContext::id()` is null, and it is null for every student — a student
 * is a member of no workspace. The trait is on the model because the constitution
 * requires it and because it guards the teacher's side; the real guard is the
 * explicit `student_user_id` condition inside every Action.
 *
 * ⚠️ `ceiling_difficulty` IS WHAT MAKES MASTERY REACHABLE AT ALL. Mastery is a
 * run of correct answers AT THE CEILING, and the ceiling is the highest
 * difficulty that has a question available to THIS student in THIS concept —
 * computed once at start and stored. Read literally as `hard`, a concept whose
 * questions are all `easy` could never be mastered: no `mastered`, no mastery
 * row, no points, and not one error anywhere. That is the family of defect this
 * repository already records as «an item that enters the denominator and can
 * never be completed».
 *
 * ⚠️ `running_key` IS THE GUARD; THE TRIPLE INDEX BELOW IT IS ONLY A FAST READ.
 * `"{student}:{concept}"` while running, NULL afterwards — the `captured_order_id`
 * idiom letter for letter. Two parallel starts each write their own attempt, so
 * `unique(attempt_id)` never bites; both sessions then reach mastery and the
 * student is awarded twice. A partial index (`WHERE status = 'running'`) is a
 * Postgres feature that does not exist on the MySQL this ships to, and NULL does
 * not collide with NULL, so every finished session coexists freely.
 *
 * No `foreign()` anywhere: this module's migrations carry none, deliberately —
 * a real constraint here would block disabling a question that has been served.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adaptive_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('student_user_id')->index();
            $table->unsignedBigInteger('concept_id')->index();

            // One attempt per session and never shared: every answer is a row in
            // `exam_answers` under an `is_practice = true` attempt with no exam,
            // which is what keeps it out of the official grade report and IN the
            // mistake notebook at the same time.
            $table->unsignedBigInteger('attempt_id');

            $table->string('current_difficulty', 8);
            $table->string('ceiling_difficulty', 8);

            // Reset on every wrong answer AND on every change of difficulty —
            // otherwise it is a sum across two levels rather than mastery of one.
            $table->unsignedSmallInteger('correct_streak')->default(0);
            $table->unsignedSmallInteger('served_count')->default(0);

            $table->string('status', 16);
            $table->string('running_key', 64)->nullable();

            $table->timestamp('mastered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique('attempt_id');
            $table->unique('running_key');
            $table->index(['student_user_id', 'concept_id', 'status']);

            // Retention sweeps by age (spec 013), and the index is the module's
            // to provide because the module owns the predicate.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adaptive_sessions');
    }
};
