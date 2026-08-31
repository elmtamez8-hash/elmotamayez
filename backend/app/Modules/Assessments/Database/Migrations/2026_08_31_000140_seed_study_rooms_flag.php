<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec 012 · T109 — the platform default row for `study_rooms`, OFF.
 *
 * ⚠️ AN UNKNOWN KEY IS ALREADY «OFF», SO THIS ROW IS NOT ABOUT BEHAVIOUR — IT IS
 * ABOUT VISIBILITY. Without it the switch does not appear in `/admin` at all, so
 * the only way to turn the feature on for a teacher is to type a key nobody has
 * written down.
 *
 * ⚠️ `DB::table()->insertOrIgnore` WITH AN EXPLICIT `uuid` AND EXPLICIT
 * TIMESTAMPS. A raw insert boots no model, so `HasUuid` never fires — and on
 * MySQL the resulting NOT NULL violation is downgraded to a warning and `''` is
 * stored, after which every later flag row collides with this one on
 * `unique(uuid)` and is silently swallowed.
 *
 * ⚠️ AND `workspace_id = 0` IS THE PLATFORM SENTINEL, NOT «no workspace». It is
 * NOT NULL because NULL never equals NULL, and a unique index carrying a nullable
 * column would let two platform defaults for one key coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->insertOrIgnore([
            'uuid' => (string) Str::orderedUuid(),
            'key' => 'study_rooms',
            'workspace_id' => 0,
            'enabled' => false,
            'description' => 'غرف المذاكرة الجماعية: مجموعة أسئلة واحدة يحلّها الأصدقاء في وقت واحد بلوحة نتائج لحظية.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The row goes, the feature does not change — an absent key reads as off.
     * Scoped to the PLATFORM row alone: a teacher who switched it on has made a
     * decision, and a rollback of this file is not a reason to undo it.
     */
    public function down(): void
    {
        DB::table('feature_flags')->where('key', 'study_rooms')->where('workspace_id', 0)->delete();
    }
};
