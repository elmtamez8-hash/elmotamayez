<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three additions with no constraint attached: `method`, `failure_reason`,
 * `settled_at`.
 *
 * `method` is the missing half of FR-018 (data-model §ز) and the third column of
 * the collection report's index — a report that can say WHAT was collected but
 * not HOW is missing the dimension an operator actually reconciles by.
 *
 * Nullable, and backfilled only where the answer is a fact rather than a guess:
 * every historic row belongs to the manual provider, which is a bank transfer by
 * definition — that is what ManualTransferProvider::createCharge() has always
 * returned. Rows from any other provider stay NULL, because inventing a method
 * for them would put an unverifiable answer in the column the report groups by.
 *
 * `failure_reason` is FR-008's — a reason the STUDENT can read, never the
 * provider's raw error. `settled_at` is when the provider finally confirmed,
 * which is not `updated_at` and not `created_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('method')->nullable()->after('status');
            $table->string('failure_reason')->nullable()->after('method');
            $table->timestamp('settled_at')->nullable()->after('failure_reason');
        });

        DB::table('payment_transactions')
            ->where('provider', 'manual')
            ->whereNull('method')
            ->update(['method' => 'bank_transfer']);
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn(['method', 'failure_reason', 'settled_at']);
        });
    }
};
