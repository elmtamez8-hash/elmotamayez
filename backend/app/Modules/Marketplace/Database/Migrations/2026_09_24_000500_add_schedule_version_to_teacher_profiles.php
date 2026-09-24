<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The token that makes «is this hour free for this teacher?» a claim, not a read.
 *
 * ⚠️ WHY A COUNTER AND NOT A UNIQUE INDEX ON `class_sessions`. The clash is an
 * OVERLAP of two intervals of any length — 14:00–15:00 against 14:30–15:30 —
 * and no unique index can express that. A point-unique on `(teacher, starts_at)`
 * would close the «same hour twice» case and leave every offset overlap racing.
 * So every write that puts a session on a teacher's calendar claims this number
 * in the same transaction: `UPDATE … SET schedule_version = v + 1 WHERE
 * schedule_version = v`, the `StructureVersion::claim()` idiom. Two writers that
 * both read `v` and both found the hour free cannot both land; the second
 * matches zero rows and is refused. Never `lockForUpdate()` — a no-op on SQLite,
 * so a test built on it proves nothing about MySQL.
 *
 * On `teacher_profiles` because the clash is per PERSON (one profile per user,
 * `unique(user_id)`), across every workspace that schedules for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('schedule_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropColumn('schedule_version');
        });
    }
};
