<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 025 · FR-024, second half — the orphan workspace is deleted, and proving
 * it empty first is part of the requirement rather than a precaution.
 *
 * ⚠️ THE PROOF IS DERIVED, NOT A HAND-WRITTEN LIST. A list ages at the first
 * table anybody adds after us, and it ages silently: the check would pass over a
 * table it has never heard of and the deletion would go ahead. 95 tables carry
 * `workspace_id` today.
 *
 * ⚠️ AND THREE MORE COLUMNS POINT AT A WORKSPACE WITHOUT CARRYING THAT NAME, so
 * the sweep is 98 checks and not 95. They are named explicitly because no scan
 * can find them:
 *   · `users.last_workspace_id` — and it has NO foreign key
 *     (`unsignedBigInteger()->nullable()->index()` alone), so deleting the row it
 *     names neither throws nor nulls it. It leaves a dangling reference in
 *     silence, which is the worst of the three.
 *   · `roles.team_id` and `model_has_roles.team_id` — spatie puts `team_id`
 *     inside the primary key, and an orphan there grants a role in a workspace
 *     that no longer exists.
 *
 * ⚠️ THE CHECK RUNS AFTER THE BACKFILL AND THE MOVE, never before: the backfill
 * writes `users.last_workspace_id` itself, so an earlier check would be measuring
 * a database two migrations out of date.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphan = DB::table('workspaces')->whereNull('owner_user_id')->first();

        /*
        | ⚠️ RETURNS QUIETLY, and that is load-bearing. `RefreshDatabase` replays
        | every migration in EVERY Feature test in this repository, against a
        | database that has never had an orphan workspace in it. A `firstOrFail`
        | here would redden the entire suite on its first test — the exact inverse
        | of the must-throw case below, and both are required.
        */
        if ($orphan === null) {
            return;
        }

        $orphanId = (int) $orphan->id;

        foreach ($this->partitionedTables() as $table) {
            /*
            | ⚠️ `exists()`, NEVER `COUNT(*)`. The question is «is it empty?», and
            | a count answers a more expensive one — it scans every matching row
            | on the path that matters, the failing one, and seven of these
            | columns carry no index on `workspace_id`. The number is also read by
            | nobody: what an operator needs is the NAME of the table that stopped
            | the deletion, which is what this message carries.
            */
            if (DB::table($table)->where('workspace_id', $orphanId)->exists()) {
                throw new RuntimeException($this->refusal($table, 'workspace_id'));
            }
        }

        foreach ($this->aliasedColumns() as [$table, $column]) {
            if (Schema::hasColumn($table, $column)
                && DB::table($table)->where($column, $orphanId)->exists()) {
                throw new RuntimeException($this->refusal($table, $column));
            }
        }

        DB::table('workspaces')->where('id', $orphanId)->delete();
    }

    /**
     * Every table carrying a `workspace_id`, read out of the schema itself.
     *
     * @return list<string>
     */
    private function partitionedTables(): array
    {
        /*
        | ⛔ NOT `information_schema`. SQLite does not have that view at all,
        | `phpunit.xml` pins the suite to `sqlite/:memory:`, and `RefreshDatabase`
        | runs every migration in every Feature test — so a query against it
        | throws in the first test of the whole suite. And the tempting fix,
        | guarding it with `driver === 'mysql'`, is worse: it makes the must-throw
        | test for this migration unrunnable on any engine anybody actually uses,
        | which is a guard that does not work in the environment the suite runs in.
        |
        | The `Schema` facade is portable and keeps the property that matters —
        | derived from the database, never typed out by hand.
        */
        $tables = [];

        foreach (Schema::getTableListing() as $table) {
            $name = str_contains($table, '.') ? (string) last(explode('.', $table)) : $table;

            if (Schema::hasColumn($name, 'workspace_id')) {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * The columns that mean «workspace» without saying so.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function aliasedColumns(): array
    {
        return [
            ['users', 'last_workspace_id'],
            ['roles', 'team_id'],
            ['model_has_roles', 'team_id'],
        ];
    }

    private function refusal(string $table, string $column): string
    {
        return "Refusing to delete the orphan workspace: {$table}.{$column} still references it. "
            .'Spec 025 · FR-024 makes proving it empty part of the requirement — the deletion '
            .'cannot be undone, so this stops rather than guessing. Move or remove that row first.';
    }

    /**
     * ⚠️ CANNOT BE UNDONE, and says so instead of pretending.
     *
     * A `down()` that re-creates the row would restore an id and nothing else —
     * no rows pointed at it any more, by construction, since that is the only
     * condition under which `up()` was allowed to run. A workspace re-added empty
     * reads as data and holds none.
     */
    public function down(): void {}
};
