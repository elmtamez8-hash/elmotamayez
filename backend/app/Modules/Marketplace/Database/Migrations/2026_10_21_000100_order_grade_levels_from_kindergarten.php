<?php

declare(strict_types=1);

use App\Modules\Marketplace\Support\MarketplaceCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «رياض الأطفال» listed LAST on every stage picker (journey audit 2026-10-21).
 *
 * The four original stages were written with sort_order 0–3; kindergarten
 * arrived later at 10, and `TaxonomySeeder` — which already states the right
 * order (10 · 20 · 30 · 40 · 50) — never overwrites a row that exists. So the
 * order is restated here, on the sort column alone: slugs carry money (see the
 * seeder's note) and are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $order = ['kindergarten' => 10, 'primary' => 20, 'preparatory' => 30, 'secondary' => 40, 'university' => 50];

        foreach ($order as $slug => $sort) {
            DB::table('grade_levels')->where('slug', $slug)->update(['sort_order' => $sort]);
        }

        // The pickers read through the marketplace cache; a stale one would
        // keep serving the old order until it expired.
        MarketplaceCache::flush();
    }

    /** Nothing to undo: the old numbers were the defect. */
    public function down(): void
    {
        //
    }
};
