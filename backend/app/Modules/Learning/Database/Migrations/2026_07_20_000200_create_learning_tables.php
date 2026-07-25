<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('student_user_id')->index();
            $table->string('source')->default('manual');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status')->default('active');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'course_id', 'student_user_id']);
            $table->index(['workspace_id', 'student_user_id']);
        });

        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('enrollment_id')->index();
            $table->unsignedBigInteger('lesson_id')->index();
            $table->string('status')->default('not_started');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('time_spent_seconds')->default(0);
            $table->json('last_position')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'lesson_id']);
        });

        Schema::create('lesson_progress_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('lesson_progress_id')->index();
            $table->string('event');
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['lesson_progress_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress_history');
        Schema::dropIfExists('lesson_progress');
        Schema::dropIfExists('enrollments');
    }
};
