<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011 · T089 — one student's subscription to one plan (data-model §٨).
 *
 * ⚠️ `order_id` IS UNIQUE, AND IT IS THE ONLY GUARD `ActivateSubscription` CAN
 * HAVE. That listener hangs off a queued event; a redelivery, a retried job or
 * an operator replaying a payment would otherwise write a SECOND active
 * subscription for one payment — access doubled in length, invisible, with the
 * ledger perfectly balanced beside it. The unique index is both the check and
 * the claim, the same idiom `captured_order_id` and `StructureVersion::claim()`
 * use, and never `lockForUpdate()` (a no-op on SQLite).
 *
 * ⚠️ `price_minor` IS A SNAPSHOT (FR-030). Reading the plan's price at renewal
 * time would let a repricing rewrite what a student already agreed to, weeks
 * after they paid. It is copied from the ORDER rather than from the plan, and
 * that distinction is not pedantry: a manual bank transfer takes days to clear,
 * and the plan can legitimately be repriced inside that lag — so the plan's
 * price at activation is a different number from the one this student was shown
 * and paid.
 *
 * ⚠️ `effective_ends_on` IS A MATERIALISED COLUMN, NOT A READ-TIME SUM.
 * Computing the freeze extension on read is broken two ways at once: it is a
 * `freeze_periods` query PER ROW on every screen that prints an end date, and
 * the nightly sweep's predicate cannot be expressed in SQL at all — so
 * subscriptions the read path considers alive would be expired by the job,
 * exactly one freeze-length early (FR-031 inverted).
 *
 * It is recomputed at FOUR moments, not one:
 *   · a freeze period is created
 *   · a freeze period is edited
 *   · ⚠️ a freeze period is DELETED — or the extension outlives its reason
 *   · ⚠️ a subscription is activated INSIDE a running freeze — or it is born
 *     without the extension it is owed
 *
 * That does not break «a freeze period is read, never written to»: what is
 * written is the subscription; the period stays the source of truth.
 *
 * ⚠️ AND EVERY COMPARISON AGAINST IT IS `< value + 1 day`, NEVER `<= value`.
 * The column is a DATE and Eloquent writes a date-cast attribute through the
 * model's datetime format, so SQLite stores `2026-09-30 00:00:00` and
 * `'2026-09-30 00:00:00' <= '2026-09-30'` is FALSE. The same boundary has now
 * cost this repository four fixes — `FreezePeriod::covering()`, the settlement
 * close, the coming-of-age sweep and 011's coupon window — and the failure is
 * always invisible on one engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Assigned explicitly from the plan, never left to
            // BelongsToWorkspace: the buyer is a student, a student is a member
            // of no workspace, and the trait fills nothing when the context is
            // null. Precedent: `credit_balances`, `store_orders`.
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_user_id')->constrained('users')->cascadeOnDelete();

            // ⚠️ THE IDEMPOTENCY CLAIM. See the class docblock.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

            $table->bigInteger('price_minor');
            $table->string('currency', 3)->default('QAR');

            $table->date('starts_on');

            // What the plan sold: `starts_on + duration_days`. Never moves.
            $table->date('ends_on');

            // What the freeze made of it. `>= ends_on` always, and the only one
            // any predicate reads.
            $table->date('effective_ends_on');

            $table->string('status', 16)->default('active');

            // Stamped by a conditional UPDATE BEFORE the notice is dispatched —
            // the `notified_dormant_at` shape. A lost reminder beats one every
            // night, and a predicate on the end date alone re-sends nightly to
            // the student least likely to want it.
            $table->timestamp('expiring_notified_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // The hot question: every lesson opened and every seat booked asks
            // «does this student hold an active subscription».
            $table->index(['student_user_id', 'status']);

            // The nightly sweep's own question, and a different one.
            $table->index(['status', 'effective_ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
