<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Tenancy\Models\PlatformSetting;
use App\Modules\Tenancy\Support\PlatformSettings;
use Illuminate\Database\Seeder;

/**
 * Writes each operational value down as a row.
 *
 * The system runs correctly without this: `PlatformSettings::get()` falls back
 * to `config/media.php`, and a settings row is an override rather than a
 * requirement. What seeding buys is visibility — an operator opening the panel
 * sees the device limit and the grant TTL as editable numbers instead of an
 * empty table that gives no hint the values exist at all.
 *
 * Existing rows are left alone. Re-running a seeder must never quietly undo a
 * limit an operator deliberately changed.
 */
class PlatformSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (array_keys(PlatformSettings::KEYS) as $key) {
            PlatformSetting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => PlatformSettings::get($key)],
            );
        }

        // The rows were read through the cache a moment ago, when they did not
        // exist yet.
        PlatformSettings::flush();
    }
}
