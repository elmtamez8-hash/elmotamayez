<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 011 · T025 — make every article slug unique across the platform, before
 * the index that will demand it.
 *
 * ⚠️ RENUMBERING COMES BEFORE THE INDEX. Spec 016 paid for the other order: an
 * index added over rows that already collide fails on live data and passes on an
 * empty test database, so the deploy is the first thing that ever runs it. The
 * two migrations are separate files for that reason and this one sorts first.
 *
 * ⚠️ AND IT COUNTS SOFT-DELETED ROWS. `cms_articles` has `softDeletes()`, and a
 * unique index does not know about them — a trashed article holds its slug
 * against the whole table. Deduping only the live rows would leave exactly the
 * collisions the index then rejects.
 *
 * The oldest row of each group keeps the slug it has: it is the one most likely
 * to be linked from outside, and a published URL that changes is a URL that
 * 404s. Everything after it takes `-2`, `-3`, … and the candidate is re-checked
 * against the whole table rather than assumed free — two groups can otherwise
 * renumber into each other («خطة» + «خطة-2» already taken by a third article).
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicated = DB::table('cms_articles')
            ->select('slug')
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('slug');

        if ($duplicated->isEmpty()) {
            return;
        }

        // Every slug in the table, so a renumbered candidate is checked against
        // what exists rather than against the group being fixed.
        $taken = array_flip(DB::table('cms_articles')->pluck('slug')->all());

        foreach ($duplicated as $slug) {
            $ids = DB::table('cms_articles')
                ->where('slug', $slug)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            // Skip the first: the oldest article keeps the URL it published.
            foreach (array_slice($ids, 1) as $position => $id) {
                $suffix = $position + 2;

                // A slug column is a `string` (255). Trimming the stem rather
                // than the suffix keeps the row insertable whatever the length.
                do {
                    $candidate = mb_substr($slug, 0, 250).'-'.$suffix;
                    $suffix++;
                } while (isset($taken[$candidate]));

                $taken[$candidate] = true;

                DB::table('cms_articles')->where('id', $id)->update(['slug' => $candidate]);
            }
        }
    }

    /**
     * ⚠️ NOT REVERSIBLE, AND SAYING SO IS THE POINT. Restoring the duplicates
     * would mean putting the table back into a state the next migration refuses
     * — and the original slug of a renumbered row is not recorded anywhere, so
     * there is nothing to restore it from. A `down()` that guessed would hand
     * back data that reads as the original and is not.
     */
    public function down(): void {}
};
