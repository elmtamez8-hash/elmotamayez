<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 021 · T042 — one open membership per (student, course), enforced by the
| database rather than by whoever remembers to check (FR-027 · SC-008).
|
| ⚠️ `closed_slot` IS THE DIFFERENCE BETWEEN A GUARD THAT BITES AND NO GUARD AT
| ALL. The obvious spelling — `unique(student_user_id, course_id)` restricted to
| `closed_at IS NULL` — is two mistakes at once. A partial index is a Postgres
| feature that DOES NOT EXIST on MySQL, and a plain unique carrying a nullable
| column never bites, because NULL never equals NULL: every closed row would
| coexist freely and so would every open one.
|
| The guard is a zero sentinel: `0` while the membership is open, and the row's
| OWN id once it closes — unique by definition, so closed rows never collide.
| The precedent in this repository is threefold: `concept_stats.lesson_id`,
| `unlock_rules.course_id`, `award_entries.reversal_of_id`.
|
| ⚠️ `course_id` IS DUPLICATED HERE ON PURPOSE. The unique index above needs it
| without a join, and an index cannot reach through `cohorts`.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohort_memberships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('cohort_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('student_user_id')->index();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_slot')->default(0);
            $table->timestamps();

            $table->unique(['student_user_id', 'course_id', 'closed_slot']);
            $table->index(['cohort_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cohort_memberships');
    }
};
