<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's name, copied onto the profile so name search does not have to
 * reach into `users`.
 *
 * Measured at 50,000 published teachers (SC-008): searching through
 * `whereHas('user', …)` cost 1116 ms — a correlated subquery evaluated per row,
 * run twice because the paginator counts and then selects. Reading the same
 * predicate off this column costs 410 ms.
 *
 * No index: the search is `LIKE '%term%'`, and a leading wildcard cannot use one.
 * Adding it would only tax every write.
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
