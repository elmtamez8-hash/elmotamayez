<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step ONE of promoting `subjects` and `grade_levels` to platform reference data
 * (spec 009 · Q8 · constitution v1.2.0 §I, layer ب).
 *
 * Until now both tables carried a workspace_id with `unique(workspace_id, slug)`,
 * and MarketplaceSeeder writes nine subjects and four grade levels INTO EVERY
 * WORKSPACE. So "الرياضيات" is a different row with a different id for every
 * teacher — which means the "cross-workspace" subject and grade leaderboard
 * scopes would collapse into per-workspace scopes, and SC-018 would pass GREEN
 * against a single-workspace fixture. ListPublicTaxonomy already compensates by
 * folding results on slug by hand; that fold is the defect wearing a workaround.
 *
 * ⚠️ THIS MIGRATION MUST RUN BEFORE THE ONE THAT ADDS `unique(slug)`. Creating
 * that index over rows that have not been collapsed yet fails the deploy on live
 * data — the literal lesson of spec 016 ("renumbering comes before the index").
 * The 000300/000310 timestamps are what orders them; do not renumber one alone.
 */
return new class extends Migration
{
    /**
     * Every column in the product that holds a `subjects.id`.
     *
     * ⚠️ NOT `courses.grade_level`, `class_sessions.grade_level`,
     * `student_profiles.grade_level_slug` OR ANY OTHER `grade_level*` COLUMN.
     * Those are SLUG STRINGS, not foreign keys — collapsing rows leaves them
     * already correct, and "fixing" them would rewrite text with an integer.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const SUBJECT_REFERENCES = [
        ['concepts', 'subject_id'],
        ['courses', 'subject_id'],
        ['class_sessions', 'subject_id'],
        ['settlement_rates', 'subject_id'],
        ['rate_change_requests', 'subject_id'],
    ];

    public function up(): void
    {
        $this->collapse('subjects', self::SUBJECT_REFERENCES, [
            ['teacher_profile_subject', 'subject_id', 'teacher_profile_id'],
        ]);

        $this->collapse('grade_levels', [], [
            ['teacher_profile_grade_level', 'grade_level_id', 'teacher_profile_id'],
        ]);
    }

    public function down(): void
    {
        // Deliberately empty. Collapsing is not reversible: the rows the losers
        // held are gone and which teacher pointed at which copy is exactly the
        // distinction this removes. The paired migration restores the COLUMN so a
        // rollback leaves a working schema — it cannot restore a difference that
        // was never meant to exist.
    }

    /**
     * Keep the lowest id per slug, re-point everything at it, delete the rest.
     *
     * @param  list<array{0: string, 1: string}>  $references  [table, column]
     * @param  list<array{0: string, 1: string, 2: string}>  $pivots  [table, column, otherKey]
     */
    private function collapse(string $table, array $references, array $pivots): void
    {
        /*
        | The map is built with a paged walk and NOTHING IS WRITTEN INSIDE IT.
        |
        | chunkById rather than chunk on principle — but the reason the writes
        | happen afterwards is the same defect from the other side: a paged walk
        | that deletes as it goes shrinks its own predicate, and every page after
        | the first skips as many rows as the previous page repaired, reporting
        | success either way (spec 016's uuid backfill).
        */
        $survivorOf = [];
        $survivorBySlug = [];

        DB::table($table)
            ->select(['id', 'slug'])
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$survivorOf, &$survivorBySlug): void {
                foreach ($rows as $row) {
                    $slug = (string) $row->slug;
                    $id = (int) $row->id;

                    if (! isset($survivorBySlug[$slug])) {
                        $survivorBySlug[$slug] = $id;

                        continue;
                    }

                    $survivorOf[$id] = $survivorBySlug[$slug];
                }
            });

        if ($survivorOf === []) {
            return;
        }

        foreach ($references as [$refTable, $refColumn]) {
            if (! Schema::hasTable($refTable) || ! Schema::hasColumn($refTable, $refColumn)) {
                continue;
            }

            foreach ($survivorOf as $loser => $survivor) {
                DB::table($refTable)->where($refColumn, $loser)->update([$refColumn => $survivor]);
            }
        }

        foreach ($pivots as [$pivotTable, $pivotColumn, $otherKey]) {
            if (! Schema::hasTable($pivotTable)) {
                continue;
            }

            foreach ($survivorOf as $loser => $survivor) {
                /*
                | The pivot's primary key is the pair, so re-pointing blindly can
                | collide: a teacher already linked to the survivor AND to a loser
                | would produce two identical rows. Drop the redundant link first,
                | then move what is left.
                |
                | Cannot happen with the data the seeder writes — a teacher profile
                | belongs to one workspace and unique(workspace_id, slug) allowed
                | it only one copy — but "cannot happen" is not a thing to find out
                | from a constraint violation half-way through a deploy.
                */
                $alreadyLinked = DB::table($pivotTable)
                    ->where($pivotColumn, $survivor)
                    ->pluck($otherKey);

                DB::table($pivotTable)
                    ->where($pivotColumn, $loser)
                    ->whereIn($otherKey, $alreadyLinked)
                    ->delete();

                DB::table($pivotTable)->where($pivotColumn, $loser)->update([$pivotColumn => $survivor]);
            }
        }

        DB::table($table)->whereIn('id', array_keys($survivorOf))->delete();
    }
};
