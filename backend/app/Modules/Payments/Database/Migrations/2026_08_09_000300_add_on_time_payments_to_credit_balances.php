<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The streak of on-time payments the credit limit rises on (FR-037 · Q-9).
 *
 * ⚠️ A COUNTER, not a history, and it is stored rather than derived because it
 * CANNOT be derived. "Paid on time" is a fact about the moment the payment
 * landed — whether the balance was overdue right then — and the payment itself
 * clears `negative_since`, so a minute later the ledger shows a clean row for a
 * student who paid twenty days late. Replaying the ledger to reconstruct the
 * answer would mean reading every entry of every balance on every sweep.
 *
 * Precedent, on this same table and for the same reason: `notified_tier` (the
 * rank of the last alert) and `negative_since` (when the balance went under).
 * Both are derived state kept because the derivation is either impossible after
 * the fact or too expensive to repeat.
 *
 * It is not money. `remaining = purchased − consumed` still holds without it, and
 * a wrong value here moves a ceiling, never a credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_balances', function (Blueprint $table): void {
            $table->unsignedInteger('on_time_payments')->default(0)->after('negative_since');
        });
    }

    public function down(): void
    {
        Schema::table('credit_balances', function (Blueprint $table): void {
            $table->dropColumn('on_time_payments');
        });
    }
};
