<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Filament\Pages\ManagePlatformSettings;
use App\Modules\Tenancy\Models\PlatformSetting;
use App\Modules\Tenancy\Support\PlatformSettings;
use Database\Seeders\PlatformSettingsSeeder;
use Illuminate\Support\Facades\Auth;

/**
 * FR-022 asks for an *adjustable* limit. A constant in a config file is
 * adjustable only by shipping code, which means nobody ever adjusts it.
 */
it('falls back to config when nothing is stored', function (): void {
    // The application has to run correctly against a database with no settings
    // seeded — a row is an override, never a requirement.
    expect(PlatformSettings::get('auth.device_limits'))
        ->toBe(config('media.device_limits'));
});

it('prefers a stored value and forgets the cache on write', function (): void {
    expect(PlatformSettings::get('auth.device_limits'))->toBe(['student' => 1]);

    PlatformSettings::set('auth.device_limits', ['student' => 3]);

    // No deploy, no cache flush by hand: the next read sees it.
    expect(PlatformSettings::get('auth.device_limits'))->toBe(['student' => 3]);
});

it('records who changed it', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);

    PlatformSettings::set('media.grant_ttl_seconds', 120, (int) $admin->getKey());

    expect(PlatformSetting::query()->find('media.grant_ttl_seconds')?->updated_by_user_id)
        ->toBe($admin->getKey());
});

it('is reachable by a platform admin and nobody else', function (): void {
    // Not a tenant permission: the device limit governs an account that enrols
    // with many teachers, so no single teacher may decide it.
    $teacher = User::factory()->create(['is_super_admin' => false]);
    Auth::login($teacher);

    expect(ManagePlatformSettings::canAccess())->toBeFalse();

    $admin = User::factory()->create(['is_super_admin' => true]);
    Auth::login($admin);

    expect(ManagePlatformSettings::canAccess())->toBeTrue();
});

/**
 * ⚠️ EVERY VALUE IN `KEYS` IS A CONFIG PATH, AND ONE OF THEM POINTED AT A FILE
 * THAT DOES NOT EXIST — `subscription.` against `config/subscriptions.php`.
 *
 * `config()` answers NULL for a path with no file behind it, and
 * `platform_settings.value` is NOT NULL: `db:seed` therefore died on the THIRD
 * of ten seeders on MySQL, and the six reference catalogues after it in
 * `DatabaseSeeder` — credit packages, gamification actions, data categories,
 * processors, regions, taxonomy — never ran at all. Every one of those is a
 * catalogue this codebase has already been bitten by for being empty.
 *
 * It could not be seen from the reading side: `ExpireSubscriptionsJob` passes
 * its own `config('subscriptions.…')` fallback explicitly, so the feature was
 * correct throughout and only the seeder ever asked the map for a default. It
 * was found on 2026-09-01 by running the seeder against production.
 *
 * This walks the map itself rather than asserting one key, because the typo is
 * a per-row mistake and the next entry added is as able to carry it.
 */
it('resolves a non-null default for every settings key', function (): void {
    $unresolved = [];

    foreach (PlatformSettings::KEYS as $key => $configPath) {
        if (config($configPath) === null) {
            $unresolved[$key] = $configPath;
        }
    }

    expect($unresolved)->toBe([]);
});

/**
 * The half above proves the map; this proves the seeder that reads it, which is
 * the thing that actually broke. A `firstOrCreate` handed an empty attribute
 * array writes NULL into a NOT NULL column — and on SQLite, where every test in
 * this repository runs, that is the same violation MySQL raised in production.
 */
it('seeds a row for every settings key', function (): void {
    PlatformSetting::query()->delete();

    $this->seed(PlatformSettingsSeeder::class);

    expect(PlatformSetting::query()->count())->toBe(count(PlatformSettings::KEYS));
    expect(PlatformSetting::query()->whereNull('value')->count())->toBe(0);
});
