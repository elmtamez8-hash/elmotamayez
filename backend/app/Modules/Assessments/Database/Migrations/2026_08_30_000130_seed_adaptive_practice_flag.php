<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec 012 · T042 — the platform default row for `adaptive_practice`, OFF.
 *
 * ⚠️ AN UNKNOWN KEY IS ALREADY «OFF», SO THIS ROW IS NOT ABOUT BEHAVIOUR — IT IS
 * ABOUT VISIBILITY. Without it the switch does not appear in `/admin` at all, so
 * the only way to turn the feature on for a teacher is to type a key nobody has
 * written down. The migration that created the table says it in its own words: a
 * flag whose meaning lives only in a commit message is a flag nobody dares flip.
 *
 * ⚠️ `DB::table()->insertOrIgnore` WITH AN EXPLICIT `uuid` AND EXPLICIT
 * TIMESTAMPS. A raw insert boots no model, so `HasUuid` never fires — and on
 * MySQL the resulting NOT NULL violation is downgraded to a warning and `''` is
 * stored, after which every later flag row collides with this one on
 * `unique(uuid)` and is silently swallowed. `insertOrIgnore` rather than
 * `insert` so a re-run over a database that already has the row is a no-op
 * instead of a failed deploy.
 *
 * ⚠️ AND `workspace_id = 0` IS THE PLATFORM SENTINEL, NOT «no workspace». It is
 * NOT NULL because NULL never equals NULL and a unique index carrying a nullable
 * column would let two platform defaults for one key coexist — the defect this
 * tree has now shipped four times over.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_flags')->insertOrIgnore([
            'uuid' => (string) Str::orderedUuid(),
            'key' => 'adaptive_practice',
            'workspace_id' => 0,
            // OFF at the platform level. A teacher's own row overrides it, so
            // turning it on for one workspace is one switch in the panel.
            'enabled' => false,
            'description' => 'التدريب التكيّفي: يختار السؤال التالي بحسب أداء الطالب حتى يتقن الفكرة.',
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
        DB::table('feature_flags')->where('key', 'adaptive_practice')->where('workspace_id', 0)->delete();
    }
};
