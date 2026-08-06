<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('teacher_profile_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('title');
            // Declared, never inferred from seats_total (FR-001أ).
            $table->string('type', 16);
            $table->string('status', 16)->default('scheduled');

            // Stored UTC, like availability_slots. A session on a daylight-saving
            // boundary must not shift, and the declared display zone is a setting.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            // Derived once and stored: every time calculation in the phase reads
            // it, and recomputing from two timestamps in each of them is four
            // chances to disagree.
            $table->unsignedSmallInteger('duration_minutes');

            $table->unsignedSmallInteger('seats_total');
            // Only ever changed by a conditional UPDATE guarded on
            // `seats_taken < seats_total` (research §R5). Never read-then-write.
            $table->unsignedSmallInteger('seats_taken')->default(0);
            // Written once at the cancellation deadline and never recomputed
            // (FR-060): it answers a question about a moment that has passed.
            $table->unsignedSmallInteger('billable_seats')->nullable();
            $table->timestamp('seats_frozen_at')->nullable();

            // Must never reach a payload or the frontend bundle (FR-019).
            $table->string('broadcast_provider', 32)->nullable();
            $table->string('broadcast_room_id', 191)->nullable();
            $table->timestamp('room_opened_at')->nullable();
            $table->timestamp('room_closed_at')->nullable();

            $table->string('recording_status', 16)->nullable();
            $table->unsignedTinyInteger('recording_attempts')->default(0);
            $table->unsignedBigInteger('media_asset_id')->nullable();

            // Delivery is not the same fact as completion (research §R7). This
            // column is what 006 and 014 will read, so it is a column and not a
            // flag inside status.
            $table->timestamp('delivered_at')->nullable();
            $table->string('interruption_note')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // Overlap checks and the teacher's own calendar (FR-003).
            $table->index(['workspace_id', 'teacher_profile_id', 'starts_at']);
            // The scheduling list, filtered by state.
            $table->index(['workspace_id', 'status', 'starts_at']);
            // The delayed jobs sweep by time, across workspaces.
            $table->index('starts_at');
        });

        Schema::create('session_bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Bridge row: workspace_id for context, student_user_id pointing at
            // the one platform-wide student (Constitution I).
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('class_session_id');
            $table->unsignedBigInteger('student_user_id');
            $table->string('status', 24)->default('booked');
            $table->boolean('is_billable')->default(true);
            $table->timestamp('booked_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            // Stops the same student holding two seats. It is NOT the guard
            // against overbooking — that is the conditional UPDATE on
            // class_sessions.seats_taken.
            $table->unique(['class_session_id', 'student_user_id']);
            $table->index(['student_user_id', 'created_at']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('class_session_id');
            $table->unsignedBigInteger('student_user_id');
            $table->string('status', 16);
            $table->string('source', 16)->default('automatic');

            $table->timestamp('first_joined_at')->nullable();
            // The reference every ping measures its delta from. Two devices
            // pinging alternately both measure from the same mark, which is why
            // the total is wall-clock and not doubled (FR-024).
            $table->timestamp('last_ping_at')->nullable();
            $table->unsignedInteger('stay_seconds')->default(0);

            // The automatic verdict survives any override (FR-025). A register
            // that hides the fact it was edited gets trusted more than it earned.
            $table->string('auto_status', 16)->nullable();
            $table->unsignedBigInteger('overridden_by')->nullable();
            $table->timestamp('overridden_at')->nullable();
            $table->string('override_reason')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            // An independent fact. It never changes `status` (FR-021د).
            $table->timestamp('recording_watched_at')->nullable();
            $table->timestamps();

            $table->unique(['class_session_id', 'student_user_id']);
            $table->index(['student_user_id', 'created_at']);
        });

        Schema::create('class_session_feedback', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('class_session_id');
            $table->unsignedBigInteger('student_user_id');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->unique(['class_session_id', 'student_user_id']);
        });

        Schema::create('freeze_periods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            // null = every student of this teacher; a value = one student only
            // (FR-039). One table rather than two because the two differ by scope,
            // not by behaviour.
            $table->unsignedBigInteger('student_user_id')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['workspace_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freeze_periods');
        Schema::dropIfExists('class_session_feedback');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('session_bookings');
        Schema::dropIfExists('class_sessions');
    }
};
