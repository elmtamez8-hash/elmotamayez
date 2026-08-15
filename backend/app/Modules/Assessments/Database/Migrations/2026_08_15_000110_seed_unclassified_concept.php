<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Step 1b. One "غير مصنّف" concept per existing workspace.
|
| ⚠️ WHY A REAL ROW AND NOT A NULLABLE COLUMN. FR-002 forbids saving a question
| with incomplete tags, and every question authored before this spec has no
| concept. A nullable `concept_id` would make "mandatory" true for new rows and
| false for old ones — one rule with two answers, and the filter that hides
| unclassified questions would hide them by accident rather than by choice. An
| explicit row is something the teacher SEES in their filter list and can act on.
|
| ⚠️ WHY DB::table AND NOT THE MODEL. `BelongsToWorkspace` fills `workspace_id`
| from `WorkspaceContext`, which resolves to null in a console process — so the
| model would write NULL into the column this whole table is partitioned by. The
| cost of going around the model is that `HasUuid` does not boot either, so
| `uuid` and the timestamps are passed EXPLICITLY. That is the same discipline
| `CreditLedger::writeEntry()` follows for the same reason.
*/
return new class extends Migration
{
    private const NAME = 'غير مصنّف';

    public function up(): void
    {
        $now = now();

        DB::table('workspaces')->orderBy('id')->chunkById(200, function ($workspaces) use ($now): void {
            $rows = [];

            foreach ($workspaces as $workspace) {
                $rows[] = [
                    'uuid' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'subject_id' => null,
                    'name' => self::NAME,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('concepts')->insertOrIgnore($rows);
            }
        });
    }

    public function down(): void
    {
        DB::table('concepts')->where('name', self::NAME)->delete();
    }
};
