<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Which payment completed the referral (owner decision 2026-09-25).
|
| ⚠️ THE REVERSAL USED TO KEY ON THE PERSON ALONE. `ReverseReferralAward` took
| the points back on ANY `PaymentReversed` of the invited student — so a friend
| who subscribed (and paid the inviter) and later had an unrelated course order
| reversed took the inviter's points with it. The owner's rule is that only the
| reversal of the order that COMPLETED the referral undoes it, and nothing
| recorded which order that was.
|
| ⚠️ NULLABLE, AND NULL MEANS «UNKNOWN», NOT «ANY». A referral still pending has
| no completing order yet; and a legacy completed row whose order cannot be
| derived without guessing stays null — the listener then reverses it on NO
| order, because reversing on an unrelated one is exactly the defect this fixes.
|
| No foreign key: `orders` belongs to Payments and this table to Identity, and a
| constraint across the two would make deleting an order depend on a referral.
| No index either: the only reader finds the row by `referred_user_id` (unique)
| and then compares this column.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('referrals', 'completing_order_id')) {
            Schema::table('referrals', function (Blueprint $table): void {
                $table->unsignedBigInteger('completing_order_id')->nullable()->after('status');
            });
        }

        $this->backfill();
    }

    /**
     * Stamp an existing completed (or already reversed) referral ONLY when exactly
     * one qualifying paid order could have completed it.
     *
     * «Qualifying» is what `CompleteReferral` accepts — a `credits` or
     * `subscription` order — that was paid (approved by hand, or captured by a
     * gateway, the capture possibly reversed since) and placed no later than the
     * completion. One candidate is the order; two or more is a guess, and a
     * wrong guess would reverse the inviter's points over the wrong refund — so
     * those rows stay null.
     *
     * `DB::table()` throughout, never a model: a migration speaks the schema of
     * its own date.
     */
    private function backfill(): void
    {
        DB::table('referrals')
            ->whereIn('status', ['completed', 'reversed'])
            ->whereNull('completing_order_id')
            ->orderBy('id')
            ->chunkById(200, function ($referrals): void {
                foreach ($referrals as $referral) {
                    $candidates = DB::table('orders')
                        ->where('user_id', $referral->referred_user_id)
                        ->whereIn('kind', ['credits', 'subscription'])
                        ->when($referral->completed_at !== null, fn ($query) => $query
                            ->where('created_at', '<=', $referral->completed_at))
                        ->where(fn ($query) => $query
                            ->where('status', 'approved')
                            ->orWhereExists(fn ($paid) => $paid
                                ->selectRaw('1')
                                ->from('payment_transactions')
                                ->whereColumn('payment_transactions.order_id', 'orders.id')
                                ->whereIn('payment_transactions.status', ['captured', 'reversed'])))
                        ->limit(2)
                        ->pluck('id');

                    if ($candidates->count() !== 1) {
                        continue;
                    }

                    DB::table('referrals')
                        ->where('id', $referral->id)
                        ->whereNull('completing_order_id')
                        ->update(['completing_order_id' => (int) $candidates->first()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table): void {
            $table->dropColumn('completing_order_id');
        });
    }
};
