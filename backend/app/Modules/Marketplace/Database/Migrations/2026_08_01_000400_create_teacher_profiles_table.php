<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->unique();

            $table->string('headline', 160)->nullable();
            $table->text('bio')->nullable();
            $table->json('qualifications')->nullable();
            $table->unsignedTinyInteger('years_experience')->default(0);
            $table->json('teaching_languages')->nullable();
            $table->decimal('hourly_rate', 10, 2)->default(0);
            $table->string('currency', 3)->default('QAR');
            $table->string('photo_path')->nullable();

            $table->boolean('is_verified')->default(false);
            $table->string('approval_status')->default('pending');
            // Derived from (approved AND workspace participates) — never set by hand.
            $table->boolean('is_publicly_listed')->default(false);

            // Null means "building": below the minimum sessions/reviews the score is
            // withheld, not zero. A new teacher is not an untrustworthy one (FR-024).
            $table->unsignedTinyInteger('trust_score')->nullable();
            $table->json('trust_score_factors')->nullable();
            $table->timestamp('trust_score_calculated_at')->nullable();

            // unsignedInteger, not tinyInteger: these grow past 255. SQLite would
            // accept the overflow silently and MySQL strict mode would reject it.
            $table->unsignedInteger('completed_sessions_count')->default(0);
            $table->unsignedInteger('cancelled_sessions_count')->default(0);
            $table->unsignedInteger('students_taught_count')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);

            $table->decimal('average_rating', 3, 2)->nullable();
            $table->unsignedTinyInteger('response_rate')->nullable();
            $table->unsignedTinyInteger('attendance_rate')->nullable();
            $table->timestamp('first_session_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for the three public list orderings (SC-008).
            $table->index(['is_publicly_listed', 'approval_status', 'trust_score']);
            $table->index(['is_publicly_listed', 'hourly_rate']);
            $table->index(['is_publicly_listed', 'average_rating']);
        });

        Schema::create('teacher_profile_subject', function (Blueprint $table) {
            $table->unsignedBigInteger('teacher_profile_id')->index();
            $table->unsignedBigInteger('subject_id')->index();
            $table->primary(['teacher_profile_id', 'subject_id']);
        });

        Schema::create('teacher_profile_grade_level', function (Blueprint $table) {
            $table->unsignedBigInteger('teacher_profile_id')->index();
            $table->unsignedBigInteger('grade_level_id')->index();
            $table->primary(['teacher_profile_id', 'grade_level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_profile_grade_level');
        Schema::dropIfExists('teacher_profile_subject');
        Schema::dropIfExists('teacher_profiles');
    }
};
