<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's claim on a reward — a BRIDGE row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redemptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('reward_id');

            /** What was actually deducted, frozen — the price may change later. */
            $table->unsignedInteger('coins_spent');

            /*
            | The month whose counter this redemption consumed.
            |
            | ⚠️ WITHOUT IT, A REJECTION AFTER THE MONTH ROLLS OVER LOSES A UNIT OF
            | STOCK FOREVER (research §R7). Releasing with a single statement
            | conditioned on "the current month" finds month_key already moved on
            | and matches nothing, so the stock is never returned for a reward
            | nobody ever received. The release is two statements for this reason:
            | stock unconditionally, the counter only if this row's month is still
            | the one on the reward.
            */
            $table->string('claimed_month_key', 7);

            /** pending · fulfilled · rejected. */
            $table->string('status', 16)->default('pending');

            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            /*
            | The two queries the two screens actually run — and there were none of
            | these at all in the first draft of the data model.
            */
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redemptions');
    }
};
