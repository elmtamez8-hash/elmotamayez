<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The buyer's page moved from `/store` to `/purchases` (2026-10-01), and `/store`
 * became the public catalogue. Notifications already written — a shipment
 * update, a refund, an approved receipt — still name `/store`, and would now
 * open the catalogue instead of the buyer's own purchases.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->where('action_url', '/store')->update(['action_url' => '/purchases']);
    }

    public function down(): void
    {
        // Not reversed: `/store` is no longer the buyer's page.
    }
};
