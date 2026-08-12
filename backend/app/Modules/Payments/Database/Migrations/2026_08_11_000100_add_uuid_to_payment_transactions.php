<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1 of 3: the column, nullable and unindexed.
 *
 * `payment_transactions` has never had a uuid because nothing ever routed to
 * one — spec 007 is the first release to put this table on a path, and
 * HasUuid's contract (getRouteKeyName() === 'uuid') is what keeps the
 * autoincrement id off the wire.
 *
 * Three migrations rather than one, in the order 016 paid for: add nullable,
 * backfill, then constrain. A unique index declared in the same breath as the
 * column fails on the first existing row — on live data, in production, after
 * a green local run against an empty table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
