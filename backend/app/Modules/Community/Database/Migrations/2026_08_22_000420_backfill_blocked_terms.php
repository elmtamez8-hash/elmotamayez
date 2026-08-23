<?php

declare(strict_types=1);

use App\Modules\Community\Support\DefaultBlockedTerms;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give every workspace that ALREADY EXISTS its starting term list.
 *
 * ⚠️ THE LISTENER FIRES ON CREATION AND ON NOTHING ELSE, so without this file the
 * filter is inert for every teacher on the platform today — and a filter that
 * permits everything **in silence** is the worst shape an absent guard can take:
 * nothing errors, nothing is logged, and the first anyone knows of it is a
 * screenshot. The same trap the permission grants one directory over were written
 * for, reached from the data side.
 *
 * ⚠️ AND IT WALKS WITH `chunkById`. `chunk()` paginates by OFFSET while the rows
 * it is filtering by change underneath it — the 016 uuid backfill lesson — and the
 * report at the end says success either way.
 *
 * ⚠️ AND EVERY ROW CARRIES ITS OWN `uuid` AND TIMESTAMPS. This is a query-builder
 * insert, so no model boots and `HasUuid` never fires; on MySQL the resulting NOT
 * NULL violation is downgraded to a warning and `''` is stored, after which every
 * later term on the platform collides with that row on `unique(uuid)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('workspaces')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($workspaces) use ($now): void {
                $rows = [];

                foreach ($workspaces as $workspace) {
                    foreach (DefaultBlockedTerms::rows() as $term => $policy) {
                        $rows[] = [
                            'uuid' => (string) Str::uuid(),
                            'workspace_id' => $workspace->id,
                            'term' => $term,
                            'policy' => $policy->value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    // A workspace created between the deploy and this migration
                    // already holds its rows; the unique index is the guard and a
                    // duplicate must not abort the deploy.
                    DB::table('blocked_terms')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('blocked_terms')->whereIn('term', array_keys(DefaultBlockedTerms::rows()))->delete();
    }
};
