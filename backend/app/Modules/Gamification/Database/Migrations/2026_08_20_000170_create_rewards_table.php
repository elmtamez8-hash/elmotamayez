<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's shop — WORKSPACE-owned (layer 2). What the teacher produces.
 *
 * The two counter columns exist so that claiming stock and claiming a slot under
 * the monthly cap are ONE conditional statement (research §R7). They are not
 * derived from `redemptions`: deriving them would mean counting rows inside the
 * write path, which is the read-then-write race again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');

            $table->string('title');
            $table->unsignedInteger('price_coins');
            $table->unsignedInteger('stock')->default(0);

            /** discount · printed · deadline_extension · streak_shield. */
            $table->string('type', 32);

            /*
            | Null = uncapped.
            |
            | ⚠️ AND THE CLAIM STATEMENT MUST TEST FOR NULL EXPLICITLY. Without
            | `monthly_cap IS NULL` in the predicate, every redemption after the
            | first in a month is refused on an UNCAPPED reward — including the
            | streak shield itself, which is the one thing students buy repeatedly.
            |
            | Mandatory for money-valued types, enforced in the Action and not in
            | the FormRequest alone (FR-031 · SC-012): the seeder and the panel
            | reach the same write with no form behind them.
            */
            $table->unsignedSmallInteger('monthly_cap')->nullable();

            /*
            | The month the counter below belongs to, `Y-m`.
            |
            | NOT NULL with an empty default, because it is read inside a
            | comparison in the claim statement — a NULL there makes the whole
            | predicate NULL and the claim silently never matches.
            */
            $table->string('month_key', 7)->default('');
            $table->unsignedSmallInteger('month_redeemed')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['workspace_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
};
