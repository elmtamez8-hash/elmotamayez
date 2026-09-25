<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| The platform zone has ONE source now: `SESSIONS_TIMEZONE`.
|
| The `sessions.timezone` row was what the panel wrote and what
| `SessionSettings::timezone()` read, while the scheduler and quiet hours read
| the environment — two sources for one fact. The code no longer reads the row,
| so it is removed rather than left behind to look like the setting that counts.
|
| ⚠️ Production holds `Asia/Qatar` in that row, and no file in this repository
| sets `SESSIONS_TIMEZONE`, so the environment resolves to its default —
| `Asia/Qatar` — which the scheduler was already running on: nothing moves. An
| operator who DID set the variable had the scheduler on it already; this makes
| the display and the billing day follow it too. `down()` does not restore the row — the code
| that would read it is gone, and `SessionSettings` falls back to config either
| way.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->where('key', 'sessions.timezone')->delete();

        Cache::forget('platform_settings:sessions.timezone');
    }

    public function down(): void {}
};
