<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `periodic_reviews (created_at)` — for the nightly retention sweep.
 *
 * `CommunityPersonalData::expire()` deletes with
 * `WHERE created_at < ? [AND student_user_id NOT IN (...)] LIMIT n` across the
 * whole platform. No existing index leads with `created_at` (the table carries
 * `workspace_id`, `teacher_user_id`, `(student_user_id, published_at)` and the
 * period unique), so every night the sweep read every review ever written.
 *
 * Hand-named, 33 characters: MySQL refuses an identifier over 64 and SQLite has
 * no limit at all, so only the deploy would say.
 */
return new class extends Migration
{
    private const INDEX = 'periodic_reviews_created_at_index';

    public function up(): void
    {
        if (Schema::hasIndex('periodic_reviews', self::INDEX)) {
            return;
        }

        Schema::table('periodic_reviews', function (Blueprint $table): void {
            $table->index('created_at', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('periodic_reviews', self::INDEX)) {
            return;
        }

        Schema::table('periodic_reviews', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
    }
};
