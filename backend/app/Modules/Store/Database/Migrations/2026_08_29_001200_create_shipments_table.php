<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T031 — where a printed book is going, and how far along it is.
 *
 * ⚠️ NO `region_id`, AND THE OMISSION IS WHAT KEEPS US1 SHIPPABLE ALONE. A
 * foreign key to `regions` would tie this wave to a table that lands five waves
 * later — and «US1 merges green on its own» is the only claim the whole phase
 * plan rests on.
 *
 * ⚠️ THE ADDRESS IS FREE TEXT, COPIED AS A SNAPSHOT. It is not a join to the
 * buyer's profile: a student who moves house in November must not silently
 * rewrite the destination of a parcel posted in September. The same reason
 * `billable_seats` is written once and never recomputed — it answers a question
 * about a moment that has passed.
 *
 * ⚠️ AND THIS TABLE IS WHY THE MODULE NEEDS A `PersonalDataOwner`. It carries a
 * child's home address and phone number. `PersonalDataContractCoverageTest`
 * derives modules from their migration directories and exempts three, of which
 * `Store` is not one — so the build goes red the moment this file lands without
 * `StorePersonalData` and its `data_categories` row beside it (T032).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id');

            // One shipment per purchase. A second parcel for one sale is a
            // decision nobody has made, and the unique index is what makes a
            // retried fulfilment idempotent here as well.
            $table->unsignedBigInteger('store_order_id')->unique();

            $table->string('recipient_name');
            $table->string('phone', 32);
            $table->string('address_line');
            $table->string('notes')->nullable();

            // Default rather than a value the creator supplies: a parcel that
            // exists has not been packed yet, and a column with no default is a
            // column every caller has to remember — including the factory, the
            // seeder and the panel, which is three chances to disagree.
            $table->string('status', 24)->default('pending');
            $table->string('tracking_ref')->nullable();

            // Stamped by the conditional transition that moved it. Not derived
            // from `updated_at`, which moves for every unrelated write to the
            // row — the reason `notified_dormant_at` exists.
            $table->timestamp('status_changed_at')->nullable();

            $table->timestamps();

            // The teacher's fulfilment queue.
            $table->index(['workspace_id', 'status']);

            // The retention sweep's own predicate: `created_at < :cutoff`,
            // platform-wide with no workspace in it. The module owns the
            // predicate, so the module owns the index — which is exactly why
            // `PersonalDataOwner::expire()` exists rather than one central query
            // that knows everybody's tables.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
