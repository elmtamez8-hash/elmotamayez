<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separate from the backfill so down() means something.
     *
     * The column was a free-form JSON blob holding a path on a public disk — a
     * permanent link that worked forever for anyone who copied it. It is
     * replaced, not extended: leaving it would give a lesson two sources of truth
     * for its video.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('lessons', 'media')) {
            return;
        }

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('media');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->json('media')->nullable();
        });
    }
};
