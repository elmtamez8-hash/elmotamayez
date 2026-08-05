<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the viewer got to, so playback resumes there (FR-036).
     *
     * Replaces `last_position`, an unvalidated JSON column with no reader and no
     * writer anywhere in the codebase — the same shape as the lessons.media blob
     * this phase removes, one table over. Adding a typed column beside it would
     * leave two places to look for one number.
     */
    public function up(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->unsignedInteger('last_position_seconds')->default(0);
        });

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropColumn('last_position');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->json('last_position')->nullable();
            $table->dropColumn('last_position_seconds');
        });
    }
};
