<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 012 · T084 — a group study room: one frozen paper, several students.
 *
 * A BRIDGE in the constitution's three layers, exactly like `adaptive_sessions`:
 * the questions are the teacher's, the students are the platform's, so the row
 * carries `workspace_id` for CONTEXT and points at platform-wide users.
 *
 * ⚠️ THERE IS NO STATUS COLUMN, AND THAT IS THE DESIGN RATHER THAN AN OMISSION.
 * «Closed» means `now() >= ends_at` — a READ, not a job. No `->delay()`, no
 * closing job, and an entire family of defect falls away with them: a delay runs
 * IMMEDIATELY on the `sync` connection, so a closing job would shut the room
 * inside the request that created it, and a bare `Queue::fake()` swallows queued
 * listeners so the test would be green about the opposite of what it claims. The
 * room has nothing to justify a job either: no broadcast provider to close, no
 * seat to release, no fee to earn.
 *
 * A stored column would also LIE the first time a worker was stopped — a room
 * whose hour has passed would read open by the column and closed by the clock.
 *
 * ⚠️ AND THERE IS NO `closed_at` COLUMN EITHER — A DEPARTURE FROM `data-model.md`,
 * RECORDED HERE BECAUSE IT WAS DELIBERATE. That document lists one, and `state()`
 * was written to honour it, and no requirement in US3 asks for an early close, so
 * NOTHING would ever have stamped it. A nullable timestamp with one reader and no
 * writer is the exact defect this repository has already paid for twice — an enum
 * value with three readers and no writer read as a requirement everybody believed
 * was implemented — and a comment saying «this has no writer» does not stop the
 * next person branching on it. Closure is `now() >= ends_at`, one condition, with
 * no second answer to disagree with it.
 *
 * If an early close is ever REQUIRED, it arrives as a column, a route that stamps
 * it and a host check, in one change. Adding the column first buys nothing and
 * costs a reader.
 *
 * ⚠️ `max_participants` IS A CEILING ENFORCED IN THE ACTION, NOT DECORATION. The
 * invitation IS the uuid and it forwards freely, so without a cap the board is N
 * rows broadcast to N subscribers for each of N×M answers.
 *
 * No `foreign()` anywhere: this module's migrations carry none, deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_rooms', function (Blueprint $table): void {
            $table->id();
            // The shareable invitation. Nothing else is handed out.
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('host_user_id')->index();

            // Null means «the whole bank this student may practise on», which is
            // a widening of FR-013 rather than a narrowing: whoever names a
            // concept gets what the spec describes.
            $table->unsignedBigInteger('concept_id')->nullable()->index();
            $table->string('difficulty', 8)->nullable();

            // What was actually DELIVERED, never what was asked for: a short
            // paper is an answer, not a failure (FR-023 · `BuildSelfExam.php:86`).
            $table->unsignedTinyInteger('question_count');
            $table->unsignedTinyInteger('max_participants');
            $table->unsignedSmallInteger('duration_minutes');

            $table->timestamp('starts_at');
            // ⚠️ THIS IS THE CLOSURE, AND THE ONLY ONE. See the class docblock.
            $table->timestamp('ends_at');

            $table->timestamps();

            $table->index(['workspace_id', 'ends_at']);
            $table->index(['host_user_id', 'ends_at']);

            // Retention sweeps by age (spec 013); the module owns the predicate
            // so the module owns the index.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_rooms');
    }
};
