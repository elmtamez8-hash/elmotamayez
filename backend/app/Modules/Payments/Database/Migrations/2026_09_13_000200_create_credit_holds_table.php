<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ٠٣٥ · T002 · T003 — حجزُ الرصيد: الحجزُ يُجمِّدُ ولا يخصم.
     *
     * ⚠️ `hold_seq` IS THE THIRD COLUMN OF THE UNIQUE KEY, AND IT IS NOT
     * DECORATION. One seat is legitimately held more than once: six places
     * release a seat, and reviving a released one is an UPDATE rather than an
     * INSERT — so «book ⇒ hold ⇒ released ⇒ settled ⇒ REVIVED» needs a second
     * hold row and a two-column key refuses it. Written with
     * `insertOrIgnore` that means a revived seat with NO hold at all — a free
     * session, with the nightly invariant green because the row was never
     * written; written directly it is a 500 on a legitimate re-booking.
     *
     * ⚠️ AND THE DISCRIMINATOR IS NOT NULL WITH A ZERO DEFAULT, never a
     * nullable column: NULL never equals NULL on either engine, so a key
     * carrying a nullable part stops biting on the one row it exists to
     * guard. This repository has paid for that three times
     * (`concept_stats.lesson_id` · `unlock_rules.course_id` ·
     * `award_entries.reversal_of_id`), each fixed with a zero sentinel.
     *
     * ⚠️ `settled_at` IS DELIBERATELY OUTSIDE THE UNIQUE KEY: it is the
     * claim condition, not part of the identity.
     *
     * ⚠️ AND THE FIRST INDEX IS KEYED ON THE SESSION, not on the balance.
     * Nine of the ten reads in this shipment start from a session (settle at
     * close · session cancelled · freeze period · single cancellation ·
     * the ineligible sweep · subscription end · cohort transfer · abandon).
     * Without it, stamping one twenty-seat session reads every live hold on
     * the platform.
     *
     * Index names are explicit: the generated unique name is 63 characters,
     * one under MySQL's 64-character ceiling (error 1059) — and SQLite has no
     * ceiling at all, so a generated name is green in every test run and
     * fails on the first production migration.
     */
    public function up(): void
    {
        Schema::create('credit_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('credit_balance_id');
            $table->unsignedBigInteger('class_session_id');
            // For the guard, and for «when does your first hold release».
            $table->unsignedBigInteger('student_user_id');

            // Context, assigned EXPLICITLY in the Action and never by the
            // trait's auto-fill: the writer is a student's request (a student
            // is a member of no workspace) and the settler is a queued job —
            // both with no context at all, so auto-fill writes nothing.
            $table->unsignedBigInteger('workspace_id');

            // Signed, for the ERROR 1690 reason written on `held_credits`.
            $table->integer('credits')->default(1);

            $table->timestamp('held_at');
            // The claim condition. NULL means «not settled yet».
            $table->timestamp('settled_at')->nullable();
            // `charged` or `released`, written with the settlement.
            $table->string('outcome', 16)->nullable();

            $table->unsignedInteger('hold_seq')->default(0);

            $table->timestamps();

            $table->unique(
                ['credit_balance_id', 'class_session_id', 'hold_seq'],
                'credit_holds_balance_session_seq_unique'
            );

            $table->index(['class_session_id', 'settled_at'], 'credit_holds_session_settled_index');
            $table->index(['credit_balance_id', 'settled_at'], 'credit_holds_balance_settled_index');
            $table->index(['student_user_id', 'settled_at'], 'credit_holds_student_settled_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_holds');
    }
};
