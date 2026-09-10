<?php

declare(strict_types=1);

use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Support\PublicFieldAllowlist;

/*
| The public regions list, walked field by field (spec 011 · T115 · T118).
|
| ⚠️ DEFERRED FROM PHASE 7 ON PURPOSE, AND THIS IS WHY IT ARRIVES HERE. The route
| did not exist yet in that wave, and an exposure test aimed at a route that is
| not there passes over an empty response — which is precisely the defect the
| test exists to catch, wearing the shape of a green build.
|
| ⚠️ AND THE ASSERTIONS USE ASCII NEEDLES OR STRUCTURE, NEVER ARABIC TEXT.
| `getContent()` escapes non-ASCII, so `not->toContain('الدوحة')` is true of a
| payload that publishes it — every exposure test in this product is written
| around that.
*/

beforeEach(function (): void {
    $this->asGuest();
});

it('publishes only the two fields a picker needs', function (): void {
    $payload = $this->getJson('/api/v1/marketplace/regions')->assertOk()->json();

    expect($payload)->not->toBeEmpty();

    $unlisted = [];
    $forbidden = [];

    foreach ($payload as $region) {
        foreach (array_keys($region) as $key) {
            if (! in_array($key, ['slug', 'name'], true)) {
                $unlisted[] = $key;
            }

            // FORBIDDEN is the stronger statement — never, at any depth — so a
            // key wrongly added to the pair above still fails here.
            if (in_array($key, PublicFieldAllowlist::FORBIDDEN, true)) {
                $forbidden[] = $key;
            }
        }
    }

    expect($unlisted)->toBe([])
        ->and($forbidden)->toBe([]);
});

it('publishes neither the row id nor the uuid', function (): void {
    // Nothing public addresses a region by anything but its slug, so an
    // identifier here would be a field published for no reader at all.
    $body = $this->getJson('/api/v1/marketplace/regions')->getContent();

    expect($body)->not->toContain('"id"')
        ->not->toContain('"uuid"');
});

it('hides a retired region from the public list', function (): void {
    Region::query()->where('slug', 'al-khor')->update(['is_active' => false]);

    $slugs = collect($this->getJson('/api/v1/marketplace/regions')->json())->pluck('slug');

    expect($slugs)->not->toContain('al-khor')
        ->and($slugs)->toContain('doha');
});
