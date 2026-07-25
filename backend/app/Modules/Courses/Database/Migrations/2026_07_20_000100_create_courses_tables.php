<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('slug');
            $table->longText('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('draft');
            $table->string('visibility')->default('private');
            $table->boolean('is_sequential')->default(true);
            $table->string('language', 5)->default('en');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->string('title');
            $table->unsignedSmallInteger('order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('course_chapters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('section_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->string('title');
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('section_id')->index();
            $table->unsignedBigInteger('chapter_id')->index();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('type')->default('article');
            $table->longText('content')->nullable();
            $table->unsignedSmallInteger('order')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->boolean('is_preview')->default(false);
            $table->boolean('is_free')->default(false);
            $table->json('media')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'course_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_chapters');
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('courses');
    }
};
