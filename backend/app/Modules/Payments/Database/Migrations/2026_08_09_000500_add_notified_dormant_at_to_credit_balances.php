<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this balance was last told it was sitting unused (Q-8).
 *
 * ⚠️ A COLUMN RATHER THAN A DERIVATION, for the reason `notified_tier` is one:
 * the notice is a transaction-free event, so nothing about it moves
 * `last_transaction_at` — and a predicate built on that column alone re-sends the
 * same reminder every night to the student least likely to want it.
 *
 * Cleared by the ledger on the next movement, so a student who comes back and
 * goes quiet again a year later is reminded again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_balances', function (Blueprint $table): void {
            $table->timestamp('notified_dormant_at')->nullable()->after('last_transaction_at');
        });
    }

    public function down(): void
    {
        Schema::table('credit_balances', function (Blueprint $table): void {
            $table->dropColumn('notified_dormant_at');
        });
    }
};
