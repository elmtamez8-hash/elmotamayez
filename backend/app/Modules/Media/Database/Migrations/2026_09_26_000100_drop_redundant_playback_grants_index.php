<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `playback_grants_user_id_index` — covered by
 * `playback_grants_user_id_media_asset_id_expires_at_index`, of which it is a
 * strict LEADING PREFIX (measured on production 2026-09-26). Every read that
 * used it can use the wider one; every grant written was paying for a copy.
 *
 * ⚠️ NO FOREIGN KEY RIDES ON `user_id` — the table was created with a plain
 * `unsignedBigInteger` and no later migration constrains it — so neither MySQL
 * nor SQLite has a constraint that needs this index. Were one added later, the
 * covering index satisfies it.
 *
 * Guarded by `hasIndex()` both ways; `down()` recreates it under its exact
 * production name.
 */
return new class extends Migration
{
    private const INDEX = 'playback_grants_user_id_index';

    public function up(): void
    {
        if (Schema::hasIndex('playback_grants', self::INDEX)) {
            Schema::table('playback_grants', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('playback_grants', self::INDEX)) {
            Schema::table('playback_grants', fn (Blueprint $table) => $table->index('user_id', self::INDEX));
        }
    }
};
