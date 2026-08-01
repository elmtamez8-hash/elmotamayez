<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache keys for the public marketplace, and the one place that clears them.
 *
 * Public responses are cached for config('marketplace.cache_ttl_seconds') — 60s,
 * the bound SC-010 puts on how long a status change may take to reach the
 * marketplace. The TTL alone satisfies that, but anything that unpublishes a
 * teacher (approval revoked, suspension, a workspace withdrawing) should take
 * effect at once rather than after up to a minute of stale results.
 *
 * Invalidation is a version counter folded into every key rather than a list of
 * keys to forget. The set of cached filter permutations is unbounded, so
 * enumerating them is impossible and missing one leaves a suspended teacher
 * visible. Bumping the version orphans all of them in a single write; the strays
 * expire on their own TTL.
 *
 * Every Action that changes public visibility must call flush(). It is a named
 * seam so that obligation is greppable.
 */
final class MarketplaceCache
{
    private const PREFIX = 'marketplace:';

    private const VERSION_KEY = self::PREFIX.'version';

    public static function ttl(): int
    {
        return (int) config('marketplace.cache_ttl_seconds');
    }

    /** Version-scoped cache key, e.g. key('stats') => "marketplace:v3:stats". */
    public static function key(string $suffix): string
    {
        return self::PREFIX.'v'.self::version().':'.$suffix;
    }

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /** Invalidate every cached public response. */
    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }
}
