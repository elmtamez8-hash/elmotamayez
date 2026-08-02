<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('teacher_profile_id')->index();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedTinyInteger('rating'); // 1–5
            $table->text('comment')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            // One live row per pair (FR-019). Re-reviewing updates, so the average
            // cannot be inflated by a student submitting the same opinion twice.
            $table->unique(['teacher_profile_id', 'student_id']);
            $table->index(['teacher_profile_id', 'is_visible', 'created_at']);
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('teacher_profile_id')->index();
            $table->unsignedBigInteger('reported_by')->index();
            $table->text('reason');
            $table->string('status', 20)->default('open');
            // Only a confirmed complaint costs the teacher points, so the timestamp
            // that matters is the one on the decision, not on the report.
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['teacher_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('reviews');
    }
};
