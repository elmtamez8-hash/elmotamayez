<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's name, copied onto the profile so name search does not have to
 * reach into `users` through a correlated subquery.
 *
 * Measured at 50,000 published teachers (SC-008), with planner statistics
 * present: `whereHas('user', …)` costs 108 ms, this column 55 ms. Both clear the
 * 1000 ms budget comfortably; the column is a 2× improvement, not a rescue.
 *
 * READ THIS BEFORE CITING THE ORIGINAL NUMBER. The first measurement put
 * whereHas at 1116 ms and this change was made to "fix" it. That figure was an
 * artefact: the benchmark database had been bulk loaded and never ANALYZE'd, so
 * SQLite was choosing plans with no statistics and *every* listing ran 3–6×
 * slow. One ANALYZE (114 ms) moved the whole suite inside budget. If you are
 * chasing a slow listing, check for statistics before restructuring a query.
 *
 * No index on this column: the search is `LIKE '%term%'`, a leading wildcard
 * cannot use one, and adding it would only tax every write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->string('search_name')->nullable()->after('user_id');
        });

        // Backfill in SQL, not in PHP: a chunked Eloquent loop over an existing
        // production table would be minutes of queries for one string join.
        DB::statement(match (DB::getDriverName()) {
            'sqlite' => "update teacher_profiles set search_name = trim((select users.first_name || ' ' || coalesce(users.last_name, '') from users where users.id = teacher_profiles.user_id))",
            default => "update teacher_profiles p join users u on u.id = p.user_id set p.search_name = trim(concat(u.first_name, ' ', coalesce(u.last_name, '')))",
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table) {
            $table->dropColumn('search_name');
        });
    }
};
