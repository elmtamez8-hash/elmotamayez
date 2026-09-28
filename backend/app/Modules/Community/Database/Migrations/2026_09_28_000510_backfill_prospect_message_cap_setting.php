<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The prospect cap's row, on a database that was seeded before the key existed.
 *
 * `PlatformSettings::get()` falls back to `config/community.php`, so the product
 * is correct without this row — what the row buys is an operator who can SEE the
 * number in «إعدادات المنصّة» and move it. `PlatformSettingsSeeder` writes it on a
 * fresh database; production is never re-seeded, which is the «a catalogue row a
 * release adds never reaches an existing database» defect in `database.md`.
 *
 * Written only when absent — an operator's value is never reset by a deploy —
 * and through `DB::table()`, never the model: a migration speaks the schema of
 * its own date.
 */
return new class extends Migration
{
    public function up(): void
    {
        $key = 'community.chat.prospect_message_cap';

        if (DB::table('platform_settings')->where('key', $key)->exists()) {
            return;
        }

        DB::table('platform_settings')->insert([
            'key' => $key,
            'value' => json_encode((int) config('community.chat.prospect_message_cap', 3)),
            'updated_by_user_id' => null,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        // `PlatformSettings::get()` caches forever, including a miss.
        Cache::forget('platform_settings:'.$key);
    }

    public function down(): void
    {
        // Left in place: removing it changes nothing the fallback does not
        // already answer, and deletes a value an operator may have tuned.
    }
};
