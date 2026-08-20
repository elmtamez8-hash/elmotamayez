<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coins, split by context — a BRIDGE row (Q0 · FR-028ج).
 *
 * The progress file is one per person; the purse is one per teacher, because the
 * reward shop belongs to the teacher and it is the teacher who bears the cost of
 * what is redeemed. Coins earned with one teacher are not spendable with another.
 *
 * ⚠️ AND THERE IS NO TOTAL. No payload in this phase sums these rows, because no
 * sum is correct: a displayed total promises something the shop refuses on the
 * first attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_balances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('workspace_id');

            /*
            | Unsigned, and safe because the deduction is
            |   SET coins = coins - :n WHERE coins >= :n
            | — the condition rules out the underflow BEFORE the assignment is
            | evaluated. Written the other way round (assign, then check) MySQL
            | raises ERROR 1690 on unsigned arithmetic and SQLite happily stores
            | a negative, so the local suite would be green about the one engine
            | that fails.
            */
            $table->unsignedInteger('coins')->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_balances');
    }
};
