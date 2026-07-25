<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('name');
            $table->longText('html_template');
            $table->json('defaults')->nullable();
            $table->timestamps();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->string('certificate_number')->unique();
            $table->string('verification_code', 64)->unique();
            $table->unsignedBigInteger('enrollment_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('student_user_id')->index();
            $table->unsignedBigInteger('exam_attempt_id')->nullable();
            $table->string('issue_reason');
            $table->timestamp('issued_at')->useCurrent();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Idempotency: one certificate per enrollment+course per workspace.
            $table->unique(['workspace_id', 'enrollment_id', 'course_id'], 'cert_unique_enrollment_course');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_templates');
    }
};
