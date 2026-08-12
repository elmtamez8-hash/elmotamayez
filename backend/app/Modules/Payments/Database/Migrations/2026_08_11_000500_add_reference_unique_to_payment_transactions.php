<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * `reference` becomes NOT NULL, then unique per provider.
 *
 * ⚠️ THE NOT NULL IS THE POINT, NOT TIDINESS. On MySQL and SQLite alike, NULL
 * does not collide with NULL inside a unique index — so every row written
 * without a reference slips past the guard silently, and the index that was
 * supposed to catch a double-capture catches nothing on exactly the rows most
 * likely to be wrong. Leaving the column nullable would ship a constraint that
 * looks enforced and is not.
 *
 * Rows that predate the rule are given a synthesized reference rather than a
 * NULL: `legacy-{id}` is unique by construction, obviously not a provider's
 * string, and readable as "this transaction was recorded before references were
 * required" — which is the truth, unlike an empty string.
 *
 * ⚠️ `->change()` redeclares the column in Laravel 13, so the length is written
 * out with the nullability.
 */
return new class extends Migration
{
    public function up(): void
    {
        $synthesized = 0;

        // Row by row, not one UPDATE with a concatenation: `||` concatenates on
        // SQLite and is logical OR on MySQL, and CONCAT() is the reverse. The
        // rows affected here are the handful written before references were
        // required, so the loop costs nothing and works on both engines.
        DB::table('payment_transactions')
            ->whereNull('reference')
            ->orWhere('reference', '')
            ->select('id')
            ->chunkById(500, function ($rows) use (&$synthesized): void {
                foreach ($rows as $row) {
                    DB::table('payment_transactions')
                        ->where('id', $row->id)
                        ->update(['reference' => 'legacy-'.$row->id]);

                    $synthesized++;
                }
            });

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->string('reference')->nullable(false)->change();
            $table->unique(['provider', 'reference']);
        });

        Log::info('007 constraint: payment_transactions.reference', [
            'synthesized' => $synthesized,
        ]);
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique(['provider', 'reference']);
            $table->string('reference')->nullable()->change();
        });
    }
};
