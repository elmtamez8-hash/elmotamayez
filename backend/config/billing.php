<?php

declare(strict_types=1);

/*
 * Fallbacks only.
 *
 * Every one of these is a row in `platform_settings` that an operator edits from
 * the panel; this file is what a database with nothing seeded falls back to.
 *
 * These are the PLATFORM's half of the price and of the credit policy. The
 * workspace's half — billing mode, alert thresholds, zero-balance behaviour —
 * lives in `workspaces.settings.billing`, because PlatformSettings is
 * platform-wide by construction (one key, one row, no workspace_id) and putting
 * a per-workspace mode in it would contradict FR-011 itself.
 *
 * Read them through Payments\Support\BillingSettings, never with config() from
 * an Action.
 */

return [
    /*
    | The operating fee, per session type.
    |
    | A fixed amount, never a percentage (FR-021أ): hosting a session, its
    | bandwidth, moderation and support cost the same whether the teacher asks 50
    | or 500. And it is defined per session type because hosting a group session
    | costs once, not once per student — leaving it single would silently double
    | the margin on every group class.
    */
    'operating_fee_minor' => [
        'individual' => 0,
        'group' => 0,
    ],

    /*
    | Gateway fee, in basis points (1 bp = 0.01%).
    |
    | Integer basis points rather than an integer percent: no real gateway
    | charges a whole percent, and a decimal here would put money back on a
    | floating-point type. 250 = 2.5%.
    */
    'gateway_fee_bps' => 0,

    // The fixed component every real gateway carries alongside its percentage.
    'gateway_fixed_fee_minor' => 0,

    // One currency in the first release. Amounts are integers in the minor unit.
    'currency' => 'QAR',

    /*
    | Credit limit policy (Q-9) — deliberately conservative, and switched OFF at
    | launch because the default mode is PREPAID_CREDITS, where FR-014 forbids
    | going below zero whatever these say.
    */
    'limit' => [
        // Zero without a recorded consent; this value once one exists (FR-048).
        'initial_credits' => 1,

        'increase_after_on_time' => 3,
        'increase_by_credits' => 1,
        'max_credits' => 4,

        // Days negative before the ceiling drops to zero and the student is
        // moved to prepaid (FR-040).
        'decrease_after_late_days' => 14,
    ],

    /*
    | Months of inactivity before a student holding a positive balance is sent a
    | notice of what they hold and how to request a refund (Q-8).
    |
    | A reminder, not an expiry: the balance stays and never expires, and its way
    | out is a cash refund through spec 007.
    */
    'dormant_notice_months' => 12,

    /*
    | How many sessions one payment covers, per cadence.
    |
    | A convention, not a measurement: how many sessions a month actually holds
    | depends on how often a given teacher meets a given student, which nothing
    | on the platform knows. These set the default package size and the default
    | credit ceiling; both are overridden per workspace and per student.
    |
    | Here rather than hardcoded so a platform whose students meet twice a week
    | does not need a deploy to say so.
    */
    'cadence_sessions' => [
        'per_session' => 1,
        'per_half_month' => 4,
        'per_month' => 8,
    ],

    /*
    | What a workspace that has configured nothing gets.
    |
    | The values themselves are per workspace (FR-011 · FR-028) and live in
    | `workspaces.settings.billing`; these are only what is read when that key is
    | absent, which is true of every workspace on the day this ships.
    |
    | The default MODE is not here: it is BillingMode::PrepaidCredits, named in
    | BillingSettings itself, because FR-013 makes that class the single source of
    | the mode decision and a mode string in a second file is the drift it exists
    | to prevent.
    |
    | Two thresholds, matching FR-029 and FR-030: the first alerts the student
    | alone, the second reaches their guardians. Descending, and read in order.
    */
    'workspace_defaults' => [
        'alert_thresholds' => [3, 1],
        'zero_balance_behavior' => 'block',
    ],

    /*
    | The two escrow guards (Q-11 · FR-021ط · FR-021ي).
    |
    | The platform holds a student's money until the sessions it bought are
    | delivered, and paying a teacher — or clawing money back from one — is a
    | MANUAL act (RecordTeacherPayout / RecordDeduction). Recovering money from a
    | teacher is therefore a human negotiation, so the cheap protection is to
    | stop the exposure accumulating rather than to unwind it afterwards.
    |
    | stop_selling_after_days: a course whose last delivered session is older
    | than this sells no more credits. Measured from the course's creation while
    | it has never delivered anything — a brand-new course is not a stalled one,
    | and blocking it would block every course on its first day.
    |
    | max_unredeemed_credits: the worst case, per student per course, stated as a
    | number instead of as a hope.
    */
    'stop_selling_after_days' => 60,
    'max_unredeemed_credits' => 24,

    /*
    | Ceiling on how many lots one consumption may draw from.
    |
    | Lot withdrawal is one conditional UPDATE per lot, so an unbounded loop is
    | an unbounded query count against the NFR-012 budget.
    */
    'max_lots_per_draw' => 20,

    /*
    | How long a payer is told a receipt review takes, in hours (FR-023).
    |
    | A promise, not a measurement — and it belongs to the operator running the
    | approval queue, which is why it is a platform setting they can edit rather
    | than an average computed from past approvals. An average silently changes
    | the promise every time a quiet week or a backlog moves it, and a payer who
    | was told twelve hours yesterday and forty-eight today reads that as the
    | platform having no idea.
    */
    'review_sla_hours' => 24,

    /*
    | The family discount, as a whole percent (spec 011 · FR-013 · D16).
    |
    | ZERO IS THE DEFAULT AND ZERO MEANS OFF. A discount that switches itself on
    | the day the code ships would reprice every purchase on the platform with
    | nobody having decided anything; the operator turns it on from `/admin`.
    |
    | A whole percent rather than basis points, and that is a deliberate
    | departure from the gateway fee two blocks up. It is compared against a
    | coupon's `percent` kind by `DiscountResolver` — «the highest one alone
    | applies» — and two units for one comparison is a conversion somebody gets
    | backwards, which here would silently apply a 10% family discount as 0.1%.
    | Integer either way, so no float ever touches the money.
    |
    | Discovery is `parent_student_relations` (D12), never the phone number the
    | spec first assumed: `users.phone` is a free string nobody confirmed, and a
    | discount built on a typo hands one family another family's money.
    */
    'sibling_discount' => 0,
];
