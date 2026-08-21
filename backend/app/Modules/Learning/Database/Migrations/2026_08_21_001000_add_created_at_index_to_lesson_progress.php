<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index the retention sweep walks (spec 013 · T129).
 *
 * `lesson_progress` had no `created_at` index and is one row per student per
 * lesson — the largest table any nightly predicate in this phase touches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
