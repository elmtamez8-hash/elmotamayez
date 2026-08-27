<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 021 · T043 — the history (FR-034). Appended to, never edited, never
| deleted.
|
| ⚠️ A REJECTION IS AN EVENT EXACTLY AS AN APPROVAL IS (FR-033). A log that keeps
| only what was accepted shows a student who asked three times and was refused as
| a student who never asked for anything.
|
| `UPDATED_AT = null` on the model — a row that cannot be updated has no business
| carrying the timestamp of its last update. Precedent: `ProgressHistory`.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohort_membership_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('student_user_id')->index();
            $table->unsignedBigInteger('cohort_id')->nullable();
            $table->unsignedBigInteger('from_cohort_id')->nullable();
            $table->string('event', 24);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at');

            $table->index(['course_id', 'student_user_id', 'created_at']);
            $table->index(['cohort_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cohort_membership_events');
    }
};
