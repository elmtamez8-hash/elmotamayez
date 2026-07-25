<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('course_id')->nullable()->index();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedTinyInteger('passing_score')->default(60);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_answers')->default(false);
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('exam_id')->index();
            $table->string('type')->default('mcq');
            $table->string('difficulty')->default('medium');
            $table->longText('content');
            $table->unsignedSmallInteger('points')->default(1);
            $table->longText('explanation')->nullable();
            $table->timestamps();
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('question_id')->index();
            $table->longText('content');
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('exam_id')->index();
            $table->unsignedBigInteger('enrollment_id')->nullable()->index();
            $table->unsignedBigInteger('student_user_id')->index();
            $table->string('status')->default('in_progress');
            $table->decimal('score', 6, 2)->default(0);
            $table->decimal('max_score', 6, 2)->default(0);
            $table->boolean('passed')->default(false);
            $table->unsignedInteger('random_seed');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'student_user_id', 'exam_id']);
        });

        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('attempt_id')->index();
            $table->unsignedBigInteger('question_id')->index();
            $table->json('selected_option_ids')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->unsignedSmallInteger('points')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
        Schema::dropIfExists('exam_attempts');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('exams');
    }
};
