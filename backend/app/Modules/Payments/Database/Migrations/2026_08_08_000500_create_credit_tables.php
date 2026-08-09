<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The credit engine's six tables.
 *
 * Under Payments deliberately, not in a new module: ContextIsolationTest defines
 * "the student billing context" as tablesCreatedBy('Payments'), and its own
 * comment says a hand-maintained list "goes stale the day spec 006 adds its
 * credit tables — and goes stale silently". Written here, they join that guard
 * on the day they are created, without a line of configuration.
 *
 * Two column-width decisions that SQLite cannot check for us:
 *
 *  - Credit counters are SIGNED integers. `unsigned` works locally forever and
 *    explodes on the first negative balance in MySQL alone. That includes
 *    `credit_limit_credits`: leave it unsigned and the atomic floor comparison
 *    below hits `ERROR 1690 BIGINT UNSIGNED value is out of range`, a 500 on
 *    exactly the students the guard exists to protect, and only in production.
 *
 *  - Money is bigInteger in the minor unit. Spec 014's migration says why: "2.1
 *    billion minor units is only 21 million riyals, a ceiling a platform
 *    reaches."
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Platform-owned (constitution v1.2.0 §I, kind أ) ──────────────────
        //
        // One row per student, across every teacher they ever study with. No
        // workspace_id and no BelongsToWorkspace: adding them would produce a
        // duplicate person per teacher — the mirror-image defect that
        // PlatformOwnershipTest exists to catch.
        Schema::create('student_credit_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->unique();
            $table->timestamps();
        });

        // ── Bridge ──────────────────────────────────────────────────────────
        //
        // One row per (account × course). The course is the context, not the
        // workspace: Q-7 fixes the session price on the course, and FR-009ج
        // names the intent literally — "maths credits, physics credits".
        Schema::create('credit_balances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('student_credit_account_id');

            // Denormalised from the account. Without it the teacher's panel joins
            // enrollments → accounts → balances, three tables where two would
            // do, with the middle one allowed to be missing.
            $table->unsignedBigInteger('student_user_id');

            $table->unsignedBigInteger('course_id');

            // Denormalised from the course, and assigned EXPLICITLY in the
            // Action rather than by the trait's auto-fill: the charge runs in a
            // queued listener with no workspace context, so auto-fill would
            // write the first production row with an empty tenant key.
            $table->unsignedBigInteger('workspace_id');

            // remaining = purchased − consumed, always.
            //
            // ⚠️ A refund LOWERS both `remaining` and `purchased`, and never
            // touches `consumed`. An earlier version of this note said it raised
            // `remaining` while lowering `purchased` — two opposite directions,
            // under which the invariant cannot hold. The student hands credits
            // back and takes cash (spec 007 turns the event into money), so a
            // refund is the reverse of a purchase. Raising `consumed` instead
            // would keep the invariant true while showing the student sessions
            // they never attended, which is the drift SC-001 cannot see: that
            // check reads `remaining` alone.
            $table->integer('purchased_credits')->default(0);
            $table->integer('consumed_credits')->default(0);
            $table->integer('remaining_credits')->default(0);

            // Signed, with a >= 0 check in the Action. See the class docblock.
            $table->integer('credit_limit_credits')->default(0);

            // Written when the balance goes below zero, cleared when it returns.
            // Without it the limit sweep derives "how many days negative" by
            // scanning the ledger for every balance on the platform.
            $table->timestamp('negative_since')->nullable();

            // For the dormancy notice (Q-8) — otherwise a MAX() per balance.
            $table->timestamp('last_transaction_at')->nullable();

            // Rank of the last threshold alert sent. Alerting on the DOWNWARD
            // transition only is what stops the same alert repeating for the
            // same crossing (FR-034).
            $table->unsignedTinyInteger('notified_tier')->default(0);

            $table->timestamps();

            $table->unique(['student_credit_account_id', 'course_id']);

            // The teacher's panel, driven from enrollments in this workspace.
            $table->index(['workspace_id', 'course_id', 'student_user_id']);

            // The credit-limit sweep.
            $table->index(['workspace_id', 'negative_since']);
        });

        // The append-only ledger.
        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('credit_balance_id');

            // Denormalised from the balance so the model can carry
            // BelongsToWorkspace. It is not decoration: GET /billing/transactions
            // reads this table directly, and the constitution's bridge rule wants
            // the global scope rather than a filter every caller writes by hand.
            // Assigned explicitly, like the balance's — the charge runs in a
            // queued listener with no workspace context.
            $table->unsignedBigInteger('workspace_id');

            $table->string('type', 16);

            // Signed: positive adds, negative removes. NOT derived from `type` —
            // `adjustment` has to be able to go either way.
            $table->integer('credits');

            // Explicit length: it sits in a four-column unique index.
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();

            $table->unsignedBigInteger('performed_by')->nullable();

            // Mandatory for `adjustment`, enforced in the Action.
            $table->string('reason')->nullable();
            $table->json('meta')->nullable();

            // created_at only. An updated_at column on a row that cannot be
            // updated is an invitation to update it.
            $table->timestamp('created_at')->nullable();

            // Idempotency (FR-007). Note what this does NOT cover: NULL values
            // are distinct in a unique index on both MySQL and SQLite, so a
            // manual entry with a null source_id can still be written twice.
            // That is why the Action mints source_id from a client-supplied
            // idempotency key rather than relying on the database alone.
            $table->unique(['credit_balance_id', 'type', 'source_type', 'source_id'], 'credit_tx_idempotency');

            $table->index(['credit_balance_id', 'created_at']);
        });

        // The batch. The one mutable table beside an append-only ledger.
        //
        // Lot selection used to derive "how much is left" with
        // SUM(credit_allocations) — a read followed by a write, in the single
        // place the seat rule was not applied. Two concurrent consumers both
        // read "1 left" and both insert, and SC-001 still passes because it never
        // looks at allocations. A lot is a package of 8 or 16, so the defect is
        // live from day one even with expiry switched off.
        //
        // The counter cannot live on credit_transactions: that model throws on
        // update. Hence a table.
        Schema::create('credit_lots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('credit_transaction_id')->unique();
            $table->unsignedBigInteger('credit_balance_id');
            $table->unsignedBigInteger('workspace_id');
            $table->integer('credits_total');
            $table->integer('credits_remaining');

            // Null means "never expires", and that is the launch default (Q-5).
            // Built now and switched off, because turning expiry on later is a
            // migration over credits people bought as permanent.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Draw order: undated lots last, then soonest-expiring, then oldest.
            $table->index(['credit_balance_id', 'expires_at', 'id']);
        });

        // What the draw CLAIMED. Append-only.
        //
        // It answers "which lot paid which consumption" — a question that cannot
        // be derived after the fact, because soonest-expiry-first rewrites the
        // answer retroactively every time a sooner-expiring lot arrives.
        //
        // The ONE credit table with no workspace_id, and deliberately so: it is a
        // join table between two rows that are both already scoped, reachable
        // only through transaction ids the caller has already resolved. No route
        // reads it and no payload carries it. A third copy of the tenant key here
        // would be a column nothing queries.
        Schema::create('credit_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('consumed_transaction_id');
            $table->unsignedBigInteger('lot_transaction_id');
            $table->integer('credits');
            $table->timestamp('created_at')->nullable();

            // Without this a retry duplicates allocation rows for a consumption
            // whose ledger entry was already deduplicated.
            $table->unique(['consumed_transaction_id', 'lot_transaction_id'], 'credit_alloc_unique');

            $table->index('lot_transaction_id');
        });

        // The price snapshot. Written once, never recomputed.
        //
        // Spec 015's books are generated from these retroactively, and what was
        // not captured cannot be recovered (Q-2).
        Schema::create('credit_purchases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('credit_balance_id');
            $table->unsignedBigInteger('credit_package_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('order_id');

            // Copied, not read through the package: FR-019 says disabling a
            // package must not touch credits already bought from it.
            $table->unsignedSmallInteger('credits');

            $table->bigInteger('teacher_rate_minor');
            $table->bigInteger('operating_fee_minor');
            $table->bigInteger('gateway_fee_minor');
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->timestamp('purchased_at');
            $table->timestamps();

            // Three readers: spec 015, the rate-approval screen in 014, and the
            // student's own history.
            $table->index(['workspace_id', 'purchased_at']);
            $table->index('credit_balance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
        Schema::dropIfExists('credit_allocations');
        Schema::dropIfExists('credit_lots');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('credit_balances');
        Schema::dropIfExists('student_credit_accounts');
    }
};
