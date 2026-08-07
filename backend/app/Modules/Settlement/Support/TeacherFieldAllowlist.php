<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

/**
 * The exhaustive set of keys anything reaching a teacher may contain.
 *
 * The separation between what a student pays and what a teacher is owed is the
 * whole point of this context, and a separation nobody can measure is a
 * separation that erodes one convenient field at a time. `StatementPayloadTest`
 * walks the statement AND the export against this list and fails on any key
 * outside it.
 *
 * One list, two surfaces, on purpose (FR-021): a second copy is a copy that
 * diverges, and the export is the surface everyone forgets when they add a
 * field — it is generated once and read in Excel, where nobody reviews it.
 *
 * Adding a key here is a deliberate decision that a teacher may see it. Treat it
 * as such, and read FR-018 first.
 */
final class TeacherFieldAllowlist
{
    /**
     * Keys that must NEVER reach a teacher, at any nesting depth.
     *
     * Every one of these names a fact from the OTHER context — the student's
     * money. They are listed rather than merely absent so that adding one back
     * fails a test instead of passing review (FR-018 · SC-007).
     *
     * @var list<string>
     */
    public const FORBIDDEN = [
        // What the student paid, under every name it goes by.
        'paid_amount',
        'paid_amount_minor',
        'student_paid',
        'student_paid_minor',
        'amount_paid',
        'sale_price',
        'sale_price_minor',
        'list_price',
        'price_minor',

        // What the platform kept.
        'platform_fee',
        'platform_fee_minor',
        'commission',
        'commission_minor',
        'commission_rate',
        'margin',
        'margin_minor',

        // Anything that moved the student's price.
        'coupon',
        'coupon_code',
        'discount',
        'discount_minor',
        'scholarship',
        'credit_price_minor',

        // The student's own balance, in either module's vocabulary.
        'student_balance',
        'student_balance_minor',
        'wallet_balance',
        'credits_remaining',
        'credit_balance',

        // The paper trail of the sale. A teacher holding an order uuid can ask
        // the billing context a question this context exists to prevent.
        'order_id',
        'order_uuid',
        'payment_id',
        'payment_uuid',
        'invoice_id',
        'receipt_url',

        // Autoincrement ids, as everywhere else (constitution VI).
        'id',
        'workspace_id',
        'teacher_profile_id',
        'student_user_id',
        'class_session_id',
        'settlement_rate_id',
        'settlement_period_id',
    ];

    /**
     * Every key the statement may carry, at any depth.
     *
     * Flat rather than nested: the test collects the payload's keys recursively,
     * because a forbidden field smuggled three levels down is still on the wire.
     *
     * @var list<string>
     */
    public const STATEMENT = [
        // The window.
        'period',
        'uuid',
        'starts_on',
        'ends_on',
        'status',
        'status_label',
        'next_payout_on',

        // How much work, of what kind. Counts, never money.
        'students_count',
        'units',
        'accrued',
        'disputed',
        'pending_package',
        'settled',
        'reversed',
        'by_type',
        'individual',
        'group',

        // The teacher's own price, and any request pending on it.
        'rates',
        'session_type',
        'session_type_label',
        'subject_id',
        'grade_level',
        'effective_from',
        'pending_rate_request',
        'current_amount_minor',
        'requested_amount_minor',
        'requested_at',
        'decided_at',
        'decision_reason',

        // The money — all of it the teacher's side of it.
        'currency',
        'amount_minor',
        'gross_minor',
        'deductions',
        'type',
        'type_label',
        'reason',
        'net_minor',
        'carried_in_minor',
    ];

    /**
     * Every key one unit row may carry.
     *
     * @var list<string>
     */
    public const UNIT = [
        'uuid',
        'session_type',
        'session_type_label',
        'status',
        'status_label',
        'basis',
        'amount_minor',
        'currency',
        'frozen_seats',
        'pending_reason',
        'recording_fault',
        'delivered_at',
        'accrued_at',
    ];

    /**
     * Every key a closed window or the payout against it may carry.
     *
     * Separate from STATEMENT because these are FROZEN numbers describing days
     * that have passed, not a running total — and because the period list is a
     * second surface, which is precisely the kind of thing that grows a field
     * the statement's test never sees.
     *
     * @var list<string>
     */
    public const PERIOD = [
        'uuid',
        'starts_on',
        'ends_on',
        'status',
        'status_label',
        'currency',
        'units_count',
        'gross_minor',
        'deductions_minor',
        'carried_in_minor',
        'carried_out_minor',
        'net_minor',
        'closed_at',

        // The payout. `reference` is the field the teacher actually needs: an
        // amount they cannot match against a bank line is an amount they have to
        // ask about — and asking is what a statement exists to prevent.
        'amount_minor',
        'reference',
        'method',
        'executed_at',
    ];

    /**
     * Every key one audit entry may carry.
     *
     * The auditor reads more than a teacher does — who decided, and when — but
     * not a single field more from the other context. Listing these here rather
     * than exempting the audit resource from the scan is the point: the reader
     * being trusted is not a reason for the payload to be unexamined.
     *
     * @var list<string>
     */
    public const AUDIT = [
        'event',
        'subject_type',
        'subject_uuid',
        'actor_name',
        'properties',
        'occurred_at',

        // The property bags the Actions write, flattened by the recursive walk.
        'units_count',
        'net_minor',
        'carried_out_minor',
        'amount_minor',
        'requested_amount_minor',
        'reason',
        'reference',
        'method',
        'session_type',
    ];

    /**
     * The export's unit columns, in order.
     *
     * Machine keys rather than Arabic headers, and that is the point: the export
     * shares THIS list with the API instead of a translated parallel one, so a
     * field added to one is a field the other's test sees immediately.
     *
     * @var list<string>
     */
    public const EXPORT_COLUMNS = [
        'delivered_at',
        'session_type',
        'status',
        'basis',
        'frozen_seats',
        'amount_minor',
        'currency',
    ];
}
