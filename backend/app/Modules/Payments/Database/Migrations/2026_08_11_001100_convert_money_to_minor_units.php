<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Money becomes an integer in the minor unit, on all four tables that hold it.
 *
 * ⚠️ THE COLUMNS ARE RENAMED, NOT RETYPED IN PLACE, and that is the safety
 * mechanism. `price` that keeps its name and changes its scale by a hundred
 * turns every reader nobody updated into a silent 100× error — a course listed
 * at 49.99 selling for 0.49, accepted by SQLite and unreproducible in a local
 * test. Renamed, every missed reader is an unknown-property error at Larastan
 * L8 before it is ever a wrong charge.
 *
 * ⚠️ AND THE VALUES ARE MULTIPLIED IN PHP, NOT BY THE ENGINE. Casting
 * decimal(12,2) to an integer type in place is engine-defined: MySQL and SQLite
 * do not agree on what happens to 49.99. So each table gets a new nullable
 * column, a copy walked with chunkById, and only then the drop — the same three
 * steps as the uuid chain, for the same reason. One rounded multiplication on a
 * two-decimal value is exact; what NFR-007 forbids is a float carrying a SUM.
 *
 * `price_before_discount` travels with `price` even though no task named it:
 * two money columns on one table at two different scales is a bug waiting for
 * whoever writes the discount badge.
 *
 * Currency defaults move to QAR. The old `USD` default was a silent fault in a
 * Qatari product, and with no live users there is nothing to migrate — the rows
 * are flipped rather than left mixed.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> table => [old => new] */
    private const MONEY = [
        'courses' => [
            'price' => 'price_minor',
            'price_before_discount' => 'price_before_discount_minor',
        ],
        'products' => ['price' => 'price_minor'],
        'orders' => ['amount' => 'amount_minor'],
        'payment_transactions' => ['amount' => 'amount_minor'],
    ];

    public function up(): void
    {
        foreach (self::MONEY as $table => $columns) {
            foreach ($columns as $old => $new) {
                Schema::table($table, function (Blueprint $blueprint) use ($new): void {
                    $blueprint->bigInteger($new)->nullable();
                });

                $this->copy($table, $old, $new);

                Schema::table($table, function (Blueprint $blueprint) use ($old): void {
                    $blueprint->dropColumn($old);
                });
            }
        }

        // Nullable stays on the two that were nullable before (a course may have
        // no discount price); the two that were required become required again.
        Schema::table('orders', function (Blueprint $table): void {
            $table->bigInteger('amount_minor')->nullable(false)->default(0)->change();
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->bigInteger('amount_minor')->nullable(false)->default(0)->change();
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->bigInteger('price_minor')->nullable(false)->default(0)->change();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->bigInteger('price_minor')->nullable(false)->default(0)->change();
        });

        $this->defaultCurrencyToQar();
    }

    /**
     * ⚠️ chunkById, and the multiplication in PHP.
     *
     * The predicate here does not shrink — the source column is untouched — but
     * the walk is the same shape as every other backfill in this chain, and a
     * reader comparing them should not have to work out why one is different.
     */
    private function copy(string $table, string $old, string $new): void
    {
        $copied = 0;

        DB::table($table)
            ->select('id', $old)
            ->chunkById(500, function ($rows) use ($table, $old, $new, &$copied): void {
                foreach ($rows as $row) {
                    $value = $row->{$old};

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([
                            $new => $value === null ? null : (int) round(((float) $value) * 100),
                        ]);

                    $copied++;
                }
            });

        Log::info('007 minor units: '.$table.'.'.$old.' → '.$new, ['rows' => $copied]);
    }

    private function defaultCurrencyToQar(): void
    {
        foreach (['courses', 'products', 'orders', 'payment_transactions'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('currency', 3)->default('QAR')->change();
            });

            DB::table($table)->where('currency', 'USD')->update(['currency' => 'QAR']);
        }
    }

    public function down(): void
    {
        // Not reversed. Dividing back would restore the decimal columns whose
        // string cast is the defect this migration exists to remove, and the
        // division itself is the float NFR-007 forbids.
    }
};
