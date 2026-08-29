<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Support\MarketplaceCache;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\Cache;

/**
 * The regions offered in the registration form (spec 011 · FR-042).
 *
 * ⚠️ IT DOES NOT DROP EMPTY ROWS, UNLIKE {@see ListPublicTaxonomy}. A subject
 * nobody teaches is a filter that can only ever return an empty page, so it is
 * hidden; a region nobody has registered from is exactly the region the next
 * person lives in. Filtering by population here would make a required field
 * unanswerable for the first student in every town.
 *
 * ⚠️ AND THE PAYLOAD IS TWO FIELDS. `RegionsExposureTest` walks it against
 * `PublicFieldAllowlist::FORBIDDEN`; the uuid is not published either, because
 * nothing public addresses a region by anything but its slug.
 */
class ListRegions extends Action
{
    /** @return list<array{slug: string, name_ar: string}> */
    public function handle(): array
    {
        /** @var list<array{slug: string, name_ar: string}> */
        return Cache::remember(
            MarketplaceCache::key('regions'),
            MarketplaceCache::ttl(),
            fn (): array => Region::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['slug', 'name_ar'])
                ->map(fn (Region $region): array => [
                    'slug' => $region->slug,
                    'name_ar' => $region->name_ar,
                ])
                ->all(),
        );
    }
}
