<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Filament\Pages\ManagePlatformSettings;
use App\Modules\Tenancy\Models\PlatformSetting;
use App\Modules\Tenancy\Support\PlatformSettings;
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
