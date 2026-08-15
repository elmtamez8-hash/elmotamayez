<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Step 3. Fill what step 2 added, before step 4 locks it down.
|
| ⚠️ chunkById, NEVER chunk. `chunk` paginates with LIMIT/OFFSET while the
| predicate (`uuid IS NULL`) SHRINKS underneath it: every page after the first
| skips as many rows as the previous page fixed, and the command reports success
| having silently missed most of the table. Step 4 then fails on a unique index
| — or worse, does not fail, and the missed rows stay broken. This repository has
| paid for that lesson once already, in the 016 uuid backfill.
|
| The concept id is resolved per workspace, not per row: one lookup for a whole
| chunk instead of one per question.
*/
return new class extends Migration
{
    public function up(): void
    {
        $unclassified = DB::table('concepts')
            ->where('name', 'غير مصنّف')
            ->pluck('id', 'workspace_id');

        DB::table('questions')
            ->select('id', 'workspace_id', 'content')
            ->orderBy('id')
            ->chunkById(500, function ($questions) use ($unclassified): void {
                foreach ($questions as $question) {
                    DB::table('questions')->where('id', $question->id)->update([
                        'uuid' => (string) Str::uuid(),
                        'concept_id' => $unclassified[$question->workspace_id] ?? null,
                        'bloom_level' => 'unclassified',
                        'content_hash' => hash('sha256', trim((string) $question->content)),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: step 4's own down() drops the constraints, and step 2
        // drops the columns. Blanking them here would only widen the window in
        // which the data is gone but the columns are not.
    }
};
