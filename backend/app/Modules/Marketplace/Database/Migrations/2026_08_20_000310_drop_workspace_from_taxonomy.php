<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step TWO: the taxonomy stops being tenant-owned (spec 009 · Q8).
 *
 * ⚠️ RUNS AFTER 000300, WHICH COLLAPSES THE DUPLICATES. `unique(slug)` over rows
 * that still hold one copy per workspace fails on any database with more than one
 * workspace in it — which is every real one.
 *
 * After this, `Subject` and `GradeLevel` drop BelongsToWorkspace and write access
 * becomes the platform permission `taxonomy.manage`. There is one "الرياضيات" for
 * the product, which is what makes the subject and grade leaderboard scopes mean
 * the same thing for every student on the platform.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['subjects', 'grade_levels'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            // Indexes first, in their own statement: a column still carried by an
            // index cannot be dropped, and on SQLite the drop rebuilds the table.
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique($table.'_workspace_id_slug_unique');
                $blueprint->dropIndex($table.'_workspace_id_index');
                $blueprint->dropIndex($table.'_slug_index');
            });

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('workspace_id');
            });

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unique('slug');
            });
        }
    }

    public function down(): void
    {
        /*
        | Restores a WORKING schema, not the old data.
        |
        | Every surviving row is handed to the lowest-numbered workspace, because
        | there is no longer any record of which workspace each copy belonged to —
        | 000300 deleted precisely that. A rollback therefore leaves one workspace
        | holding the taxonomy and the others with none, and re-running
        | MarketplaceSeeder is what repairs it.
        */
        $fallbackWorkspaceId = (int) (DB::table('workspaces')->min('id') ?? 0);

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique($table.'_slug_unique');
            });

            Schema::table($table, function (Blueprint $blueprint) use ($fallbackWorkspaceId): void {
                $blueprint->unsignedBigInteger('workspace_id')->default($fallbackWorkspaceId)->after('id');
            });

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->index('workspace_id');
                $blueprint->index('slug');
                $blueprint->unique(['workspace_id', 'slug']);
            });
        }
    }
};
