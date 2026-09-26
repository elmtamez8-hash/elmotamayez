<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reversed credit sale is marked on its own row (owner decision 2026-09-25).
 *
 * `credit_purchases` is what the finance screens sum as «sold»; without a mark,
 * a package whose money went back and whose credits were clawed back still
 * read as a sale standing. Written once, by `ReverseCreditOrder`, with a
 * conditional UPDATE — and deliberately not `$fillable`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('purchased_at');
        });
    }

    public function down(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table): void {
            $table->dropColumn('reversed_at');
        });
    }
};
