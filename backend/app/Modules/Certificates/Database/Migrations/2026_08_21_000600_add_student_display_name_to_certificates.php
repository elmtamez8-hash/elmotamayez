<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The name printed on the certificate, frozen at issue (spec 013 · FR-021).
 *
 * ⚠️ `GET /certificates/verify/{code}` IS PUBLIC, UNAUTHENTICATED, AND JOINS
 * `users` LIVE — which makes an erasure a three-way trap with no good move:
 * nulling the student leaves a certificate that verifies as belonging to NOBODY,
 * deleting the row destroys a credential the student actually earned, and
 * anonymising the joined name silently rewrites a public statement of fact that
 * an employer may already have checked. Freezing the name at issue is what makes
 * `ErasureMode::Retain` safe here: the certificate keeps saying what it said on
 * the day it was earned, and the account behind it can go.
 *
 * ⚠️ AND THE BACKFILL IS PART OF THE MIGRATION, NOT A FOLLOW-UP. Dropping the join
 * without it makes every certificate ever issued verify as an empty name — the
 * same class of defect as 016's `attempt_items`, where a column added without
 * building the rows behind it left every pre-existing attempt with a denominator
 * of zero and a test that measured the wrong number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->string('student_display_name')->nullable()->after('student_user_id');
        });

        /*
        | ⚠️ `chunkById`, NEVER `chunk`. The predicate (`student_display_name IS
        | NULL`) SHRINKS as the walk writes, and `chunk` paginates by OFFSET — so
        | every page after the first skips as many rows as the previous page fixed,
        | and reports success. 016's uuid backfill paid for this lesson once.
        |
        | The query builder rather than the model: `Certificate` has a workspace
        | scope, and a migration runs with no workspace context at all.
        */
        DB::table('certificates')
            ->select('certificates.id', 'users.first_name', 'users.last_name')
            ->join('users', 'users.id', '=', 'certificates.student_user_id')
            ->whereNull('certificates.student_display_name')
            ->orderBy('certificates.id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('certificates')
                        ->where('id', $row->id)
                        ->update([
                            'student_display_name' => trim($row->first_name.' '.$row->last_name),
                        ]);
                }
            }, 'certificates.id', 'id');
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropColumn('student_display_name');
        });
    }
};
