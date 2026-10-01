<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product's cover picture — a relative path on the `public` disk, served the
 * way `courses.cover_path` is (`asset('storage/'.…)`). Nullable: a product
 * without one shows the kind's icon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_items', function (Blueprint $table): void {
            $table->string('cover_path')->nullable()->after('media_asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('store_items', function (Blueprint $table): void {
            $table->dropColumn('cover_path');
        });
    }
};
