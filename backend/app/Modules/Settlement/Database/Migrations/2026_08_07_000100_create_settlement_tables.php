<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The teacher's side of the money.
 *
 * Two rules hold across every table here and are checked by
 * ContextIsolationTest rather than trusted:
 *
 *  1. **No foreign key to the student billing context** — not to `orders`, not
 *     to `payments`, not to anything 006 will add. The bridge is the
 *     `SessionDelivered` event and nothing else (FR-030).
 *  2. **Amounts are integers in the minor unit**, with their currency beside
 *     them. The existing `Payments` tables use `decimal(12,2)`, which is exact
 *     in MySQL but comes back from Laravel's cast as a *string* — and arithmetic
 *     on it in PHP goes through a float. A ledger that must match a statement to
 *     zero (FR-022) cannot rest on a type that rounds. The departure from the
 *     older pattern is deliberate; see research §R3.
 *
 * SQLite accepts any integer in any column, so widths are chosen for MySQL
 * strict mode here rather than discovered in production: bigInteger, because
 * 2.1 billion minor units is only 21 million riyals and that is a ceiling a
 * platform reaches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_rates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('teacher_profile_id');

            // Individual and group are priced separately (FR-014): hosting a
            // group session costs the platform once, not once per student, so a
            // single rate would silently double the margin on group sessions.
            $table->string('session_type', 16);

            // Null means "applies to everything". The default is one rate per
            // teacher per session type; subject and grade are the optional
            // narrowing the admin may reach for (FR-014أ).
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('grade_level', 32)->nullable();

            // A fixed amount per unit. There is deliberately NO percentage
            // column: a percentage of the sale price lets the teacher recover the
            // total with one division, which dismantles the separation without
            // touching a single screen (FR-009 · Q2أ).
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);

            $table->timestamp('effective_from');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('rate_change_request_id')->nullable();
            $table->timestamps();

            // The resolution path: workspace, teacher, type, then the newest
            // effective_from not later than the session.
            $table->index(
                ['workspace_id', 'teacher_profile_id', 'session_type', 'effective_from'],
                'settlement_rates_resolution_index',
            );
        });

        Schema::create('rate_change_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('teacher_profile_id');

            $table->string('session_type', 16);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('grade_level', 32)->nullable();

            // Both values, so the decision can be read a year later without
            // reconstructing what the rate was at the time (FR-012).
            $table->unsignedBigInteger('current_amount_minor')->nullable();
            $table->unsignedBigInteger('requested_amount_minor');
            $table->string('currency', 3);

            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('requested_at');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            /*
            | ⚠️ اسمٌ صريحٌ لأنّ المُولَّدَ يتجاوزُ ٦٤ حرفاً — سقفَ MySQL للمعرِّفات
            | (خطأ 1059). و**SQLite بلا سقفٍ إطلاقاً**، فهذا أخضرُ في كلِّ تشغيلةِ
            | اختبارٍ ويسقطُ في أوّلِ هجرةٍ على الإنتاج.
            */
            $table->index(['workspace_id', 'teacher_profile_id', 'status'], 'rate_requests_teacher_status_index');
        });

        Schema::create('teaching_units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // A BRIDGE row (constitution I): workspace_id for context, plus a
            // pointer at the platform-owned student — exactly the shape of
            // `attendances`.
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('student_user_id');

            // Whoever actually delivered it, which is not always the session's
            // owner — an assistant standing in is still the person who taught.
            $table->unsignedBigInteger('teacher_profile_id');
            $table->unsignedBigInteger('class_session_id');

            $table->string('session_type', 16);

            // The reference AND the amount. The reference alone would reprice the
            // past the first time anyone corrected a rate row; the amount alone
            // would lose the reason. The amount is the one that argues (FR-007ب).
            $table->unsignedBigInteger('settlement_rate_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);

            // Copied from the event, never recomputed from live bookings
            // (FR-007أ): it is a fact about the moment the cancellation window
            // shut, and bookings keep moving afterwards.
            $table->unsignedSmallInteger('frozen_seats');
            $table->string('basis', 40);

            $table->string('status', 24)->default('pending_package');
            $table->string('pending_reason', 191)->nullable();
            // Released despite a failed recording. Not the teacher's fault, so
            // not the teacher's loss (FR-008ج) — but recorded, because a provider
            // failing often is a fact somebody should see.
            $table->boolean('recording_fault')->default(false);
            // A session nobody booked is suspicious whatever was paid for it, so
            // the flag survives the payment decision (FR-008ح).
            $table->boolean('needs_review')->default(false);

            $table->timestamp('delivered_at');
            $table->timestamp('accrued_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('settlement_period_id')->nullable();

            // Corrections are new rows pointing back (FR-006). The original is
            // never touched, so "what did we think in March" stays answerable.
            //
            // 0 rather than NULL for "not a reversal", and that is load-bearing:
            // this column is part of the unique key below, and BOTH MySQL and
            // SQLite treat NULLs in a unique index as distinct from each other.
            // Nullable here would let two originals for the same seat sit side by
            // side and the idempotency guarantee would be decorative.
            $table->unsignedBigInteger('reversal_of_id')->default(0);
            $table->text('reversal_reason')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();

            $table->timestamps();

            // The statement's path (NFR-011): teacher, period, status.
            $table->index(
                ['workspace_id', 'teacher_profile_id', 'settlement_period_id', 'status'],
                'teaching_units_statement_index',
            );

            // What makes accrual idempotent (FR-002 · SC-002): one original per
            // seat, and any number of corrections after it, because each
            // correction carries a different `reversal_of_id`. See the column
            // above for why that id is 0 and not NULL.
            $table->unique(
                ['class_session_id', 'student_user_id', 'reversal_of_id'],
                'teaching_units_seat_unique',
            );
        });

        Schema::create('settlement_periods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('teacher_profile_id');

            // DATE columns compared against date strings, never through
            // whereDate(): a function around the column costs the index it was
            // given, and these are read on every close.
            $table->date('starts_on');
            $table->date('ends_on');

            $table->string('status', 16)->default('open');

            // Frozen at close, then read. The statement must not re-derive them —
            // a total that recomputes gives a different answer after any later
            // correction, including to a teacher who has already been paid.
            $table->unsignedInteger('units_count')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('deductions_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('carried_in_minor')->default(0);
            $table->bigInteger('carried_out_minor')->default(0);
            $table->string('currency', 3);

            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'teacher_profile_id', 'status']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('teacher_profile_id');

            $table->string('type', 24);

            // SIGNED, unlike every other amount here. Reversals, deductions,
            // payouts and a negative carry-over are all legitimately below zero,
            // and an unsigned column would turn each of them into a silent wrap
            // or a strict-mode rejection in production only.
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);

            $table->unsignedBigInteger('teaching_unit_id')->nullable();
            $table->unsignedBigInteger('settlement_period_id')->nullable();
            $table->unsignedBigInteger('payout_id')->nullable();

            $table->string('reason', 191)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            /*
            | ⚠️ اسمٌ صريحٌ لأنّ المُولَّدَ يتجاوزُ ٦٤ حرفاً — سقفَ MySQL للمعرِّفات
            | (خطأ 1059). و**SQLite بلا سقفٍ إطلاقاً**، فهذا أخضرُ في كلِّ تشغيلةِ
            | اختبارٍ ويسقطُ في أوّلِ هجرةٍ على الإنتاج.
            */
            $table->index(['workspace_id', 'teacher_profile_id', 'settlement_period_id'], 'ledger_entries_teacher_period_index');
        });

        Schema::create('teacher_payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('teacher_profile_id');
            $table->unsignedBigInteger('settlement_period_id');

            // UNSIGNED on purpose: a negative net is carried into the next period
            // and never paid (FR-026), so the column itself refuses the case
            // rather than depending on a check somebody might move.
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);

            $table->string('reference', 191)->nullable();
            $table->string('method', 32)->nullable();
            $table->timestamp('executed_at');
            $table->unsignedBigInteger('executed_by');
            $table->timestamps();

            // One payout per period. This — not a check in the Action — is what
            // makes re-running the settlement cycle safe (SC-014).
            $table->unique('settlement_period_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_payouts');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('settlement_periods');
        Schema::dropIfExists('teaching_units');
        Schema::dropIfExists('rate_change_requests');
        Schema::dropIfExists('settlement_rates');
    }
};
