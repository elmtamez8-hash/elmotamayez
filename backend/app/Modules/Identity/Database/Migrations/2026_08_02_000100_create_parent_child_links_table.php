<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parents, their children, and how each wants to be told about them.
 *
 * Neither table carries workspace_id, and that is deliberate rather than an
 * oversight: like `users`, these rows describe people on the platform, not data
 * inside an academy. A parent belongs to no workspace, so a workspace_id here
 * would be null on every row and the scope would filter nothing anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_child_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('parent_id')->index();
            // Nullable: a parent can add a child before that child has an account
            // of their own, which is the common case at signup (FR-074).
            $table->unsignedBigInteger('child_id')->nullable()->index();
            $table->string('child_name', 150);
            $table->unsignedTinyInteger('child_age')->nullable();
            $table->string('child_grade_level_slug', 100)->nullable();
            $table->timestamps();

            $table->unique(['parent_id', 'child_id']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('weekly_reports')->default(true);
            $table->boolean('session_alerts')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('parent_child_links');
    }
};
