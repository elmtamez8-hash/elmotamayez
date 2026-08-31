<?php

declare(strict_types=1);

use App\Modules\Marketplace\Support\PublicFieldAllowlist;
use App\Modules\Tenancy\Support\PlatformSettings;
use Filament\Facades\Filament;

/*
| The product's own name, read at run time.
|
| ⚠️ THE DEFECT THIS REPLACES SHIPPED AND SAT FOR MONTHS, and nothing failed while
| it did. The name lived in `NEXT_PUBLIC_PLATFORM_NAME`, which Next inlines at
| BUILD time; it was set nowhere on the server, so `lib/platform.ts` fell through
| to the placeholder «منصّتي» and every `<title>`, every Open Graph tag and the
| footer of the live site spelled a name that is not the product's. Measured on
| 2026-08-31: «منصّتي — مدرّسون خصوصيون بالعربية».
|
| A row plus this endpoint is what makes it changeable without a deploy — and the
| fallback below is what makes a database with nothing seeded still look like the
| product rather than like an unfinished one.
*/

it('answers the configured name without any authentication', function (): void {
    /*
    | ⚠️ NO `actingAs`, AND THAT IS THE ASSERTION. The name is on the LOGIN page,
    | in the `<title>` of every public page and in the web manifest — all read
    | before anybody has an account. Behind `auth:sanctum` the sign-in screen
    | could not spell the product it signs you in to.
    */
    $this->getJson('/api/v1/platform')
        ->assertOk()
        ->assertJsonPath('data.name', 'المتميز');
});

it('falls back to the real name and never to a placeholder', function (): void {
    /*
    | ⚠️ THE FALLBACK IS THE PRODUCT'S NAME, NOT «منصّتي». A fallback that reads
    | like a placeholder is a fallback nobody notices is in use — which is the
    | entire history of this value. With no row at all the answer must still be
    | the name, so a fresh database looks correct rather than merely defaulted.
    */
    expect(config('platform.name'))->toBe('المتميز')
        ->and(PlatformSettings::get('platform.name'))->toBe('المتميز');
});

it('reflects an operator edit without a deploy, and busts the cache', function (): void {
    // The whole point of the move: this is what «من إعدادات المنصة» means.
    PlatformSettings::set('platform.name', 'اسم جديد');

    $this->getJson('/api/v1/platform')
        ->assertOk()
        ->assertJsonPath('data.name', 'اسم جديد');

    /*
    | ⚠️ THE SECOND READ IS THE ONE THAT MATTERS. `PlatformSettings::get()` is
    | `Cache::rememberForever`, so an edit that did not forget the key would be
    | invisible until the cache was cleared by hand — the feature would look
    | implemented and change nothing.
    */
    expect(PlatformSettings::get('platform.name'))->toBe('اسم جديد');
});

it('sends the name and nothing else from the settings table', function (): void {
    /*
    | ⚠️ AN ALLOWLIST OF ONE FIELD. `platform_settings` holds the device limit, the
    | grant TTL, the operating fee and the gateway's basis points — a «settings»
    | endpoint that answered with `PlatformSettings::all()` would put the
    | platform's half of the price on a public URL, and every key added to that
    | table afterwards would join it silently.
    |
    | Asserted with `toBe()` on the exact key set: a test that only checked `name`
    | is PRESENT passes against a payload carrying all forty of them.
    */
    $payload = $this->getJson('/api/v1/platform')->assertOk()->json('data');

    expect(array_keys($payload))->toBe(PublicFieldAllowlist::PLATFORM_IDENTITY)
        ->and(PublicFieldAllowlist::PLATFORM_IDENTITY)->toBe(['name']);
});

it('is registered in the platform settings the panel can edit', function (): void {
    /*
    | A key the panel cannot reach is a key nobody can change, which is the state
    | this whole change exists to leave behind. `KEYS` is what
    | `ManagePlatformSettings` and `PlatformSettings::all()` both read.
    */
    expect(PlatformSettings::KEYS)->toHaveKey('platform.name');
});

/*
| ⚠️ THIS CASE EXISTS BECAUSE THE PANEL'S LOGO DID NOT FOLLOW THE ROW, AND THE
| ONLY THING THAT FOUND IT WAS CHANGING THE ROW AND LOOKING.
|
| `brandName()` was moved to a closure over the setting and `brandLogo()` was
| not — it kept building its `aria-label` from `config('app.name')`, eagerly, at
| application boot. It MATCHED, because `APP_NAME` happens to hold the same
| string, so every screen looked right: two spellings of the product's name, one
| of which had stopped being the product's name the moment an operator renamed it
| from `/admin/platform-settings`. Reading the code would not have shown it.
|
| Both are closures now, so both are resolved per request. The assertion is that
| the rendered label MOVES when the row moves — not that it equals a constant,
| which the old, broken build also satisfied.
*/
it('renders the panel brand from the settings row, not from a second source', function (): void {
    PlatformSettings::set('platform.name', 'اسمٌ للّوحة');

    $panel = Filament::getPanel('admin');

    expect((string) $panel->getBrandName())->toBe('اسمٌ للّوحة')
        ->and((string) $panel->getBrandLogo())->toContain('اسمٌ للّوحة');
});
