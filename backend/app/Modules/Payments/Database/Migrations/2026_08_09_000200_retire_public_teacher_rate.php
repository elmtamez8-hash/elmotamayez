<?php

declare(strict_types=1);

use App\Modules\Marketplace\Support\MarketplaceCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finish FR-021و on a running system: flush the formatted payloads, drop the
 * index that lost its reason.
 *
 * ⚠️ THE FLUSH IS THE POINT, AND IT IS NOT COSMETIC. Three marketplace payloads
 * are cached ALREADY RESOLVED — the teacher list, the course list and the home
 * page each store the rendered array, not the query behind it. So on the deploy
 * that removes `hourly_rate` from the Resource, every one of those keys stays on
 * the wire, correct-looking and unreachable from the code, until its TTL expires.
 * Short is not zero, and "the rate was public for another minute after we removed
 * it" is not a sentence worth writing.
 *
 * A migration rather than a deploy note because a note is a thing someone
 * remembers. `MarketplaceCache::flush()` bumps a version key, so it is safe to
 * run twice and costs nothing on a cache that is already cold.
 *
 * The index `(is_publicly_listed, hourly_rate)` existed for the price sort and
 * the price range filter, both of which are gone. An index nothing reads is
 * write cost on every profile update, paid for a query that can no longer be
 * asked.
 *
 * Lives under Payments because spec 006 is what retired the field; the column
 * itself stays where it is (T089) — it is the teacher's own input and the seed
 * of a rate-change request in 014.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->dropIndex(['is_publicly_listed', 'hourly_rate']);
        });

        MarketplaceCache::flush();
    }

    public function down(): void
    {
        Schema::table('teacher_profiles', function (Blueprint $table): void {
            $table->index(['is_publicly_listed', 'hourly_rate']);
        });

        // Flushed on the way back too: a rollback restores the field to the
        // Resource, and a cached payload built without it would hide the field
        // the rollback exists to bring back.
        MarketplaceCache::flush();
    }
};
