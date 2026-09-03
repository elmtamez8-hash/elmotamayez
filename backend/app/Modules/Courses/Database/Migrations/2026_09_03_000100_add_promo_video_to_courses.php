<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Spec 018 · T001 — the course's promotional video.
|
| Four columns, no table. The spec says one video per course (Q4), and a
| one-to-one relation is columns: a table for it would be a model, a policy, a
| factory, a migration and a case in WorkspaceIsolationTest for zero extra
| capability. `Course` already carries `BelongsToWorkspace`, so no new ownership
| layer is declared and no new isolation test is owed (constitution I).
|
| ⚠️ WHAT IS STORED IS THE VIDEO ID, NEVER THE PASTED URL (FR-008). A raw
| string sitting one line away from an `iframe src` is a phishing and
| clickjacking door that every later reader reopens; extracting at the door
| makes the mistake unrepresentable rather than caught by a check somebody
| forgets on the second path.
|
| `promo_video_status` is NOT derived from `promo_video_id`: a course holding an
| id that has not been reviewed, and a course whose id was cleared after a
| rejection, are two different states pointing at the same value.
|
| `'none'` is the default and does NOT mean «rejected» — zero rows in this
| database have ever had a link pasted, and a default that read as a refusal
| would be wrong on every screen showing the status.
|
| ⚠️ AND `promo_video_id` GOES INTO `$fillable` IN THE SAME CHANGE. A column a
| migration adds and mass assignment does not know about is a column that is
| NEVER WRITTEN, in silence — spec 013 shipped three of them at once and every
| assertion over them passed, because they were made against a response body
| that echoes what was submitted rather than what was stored.
|
| No index: nothing searches by these. The field is read with the course row the
| public page already fetches, and the review queue is a small admin list.
|
| No backfill: `null` and `'none'` are the correct state for every existing row.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('promo_video_id', 32)->nullable()->after('cover_path');
            $table->string('promo_video_status', 16)->default('none')->after('promo_video_id');
            $table->timestamp('promo_video_reviewed_at')->nullable()->after('promo_video_status');
            $table->foreignId('promo_video_reviewed_by')
                ->nullable()
                ->after('promo_video_reviewed_at')
                ->constrained('users')
                // The reviewer may leave; a course's review state must not fall
                // over with them.
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // The foreign key first, in its own statement: SQLite's native
            // DROP COLUMN refuses a column that still carries one, and every
            // test in this repository runs on in-memory SQLite. MySQL would
            // discard it silently along with the column and never complain.
            $table->dropConstrainedForeignId('promo_video_reviewed_by');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn([
                'promo_video_id',
                'promo_video_status',
                'promo_video_reviewed_at',
            ]);
        });
    }
};
