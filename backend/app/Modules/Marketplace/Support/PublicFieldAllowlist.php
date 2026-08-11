<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

/**
 * The exhaustive set of keys any public marketplace response may contain.
 *
 * Public responses are built field by field, never from $model->toArray(). This
 * class exists so the rule is enforceable rather than merely intended:
 * PublicExposureTest walks every public endpoint's payload and fails on any key
 * that is not listed here.
 *
 * Adding a key here is a deliberate decision to publish it to anonymous visitors
 * across every workspace. Treat it as such.
 */
final class PublicFieldAllowlist
{
    /**
     * Keys that must NEVER appear in a public payload, at any nesting depth.
     *
     * @var list<string>
     */
    public const FORBIDDEN = [
        'id',
        'email',
        'phone',
        'password',
        'workspace_id',
        'workspace',
        'user_id',
        'created_by',
        'step_data',
        'reviewed_by',
        'rejection_reason',
        'last_workspace_id',
        'is_super_admin',
        'platform_role',
        'internal_notes',
        /*
        | Added by spec 006, FR-021و — and it AMENDS shipped behaviour.
        |
        | 001 published the teacher's hourly rate on the card, on their profile,
        | and as a filter and a sort. 006 makes the platform the seller: the
        | student pays a cost-plus total and the teacher is paid an approved
        | settlement rate, and FR-021ب forbids the two ever meeting on a screen.
        | A published `hourly_rate` beside a published total is the whole
        | equation, solved by anyone who cares to subtract.
        |
        | The COLUMN stays (T089): it is the teacher's own input on their
        | application and the seed of a rate-change request in 014. Forbidden to
        | SHOW, not forbidden to store.
        */
        'hourly_rate',
    ];

    /** @var list<string> */
    public const TEACHER_CARD = [
        'uuid',
        'name',
        'headline',
        'photo_url',
        'subjects',
        'grade_levels',
        'years_experience',
        'teaching_languages',
        // No `hourly_rate` and no `currency` beside it: a currency with no amount
        // is a column nobody reads, and leaving it would make the removal look
        // like an oversight rather than a decision (FR-021و).
        'average_rating',
        'reviews_count',
        'trust_score',
        'trust_score_band',
        'is_verified',
        'available_now',
    ];

    /** @var list<string> */
    public const TEACHER_DETAIL = [
        ...self::TEACHER_CARD,
        'bio',
        'qualifications',
        'stats',
        'trust_score_factors',
        'courses',
        'reviews',
        'availability',
        'faqs',
    ];

    /** @var list<string> */
    public const COURSE_CARD = [
        'uuid',
        'title',
        'cover_url',
        'teacher',
        'type',
        'lessons_count',
        'duration_seconds',
        'price',
        'price_before_discount',
        'currency',
        'average_rating',
        'enrolled_count',
        'is_bestseller',
    ];

    /*
    | The nested shapes, which had no constants until spec 006 made this class
    | load-bearing.
    |
    | ⚠️ They were MISSING, not deliberately unlisted. While PublicExposureTest
    | only checked FORBIDDEN, nothing referenced these constants at all — so the
    | teacher's stats block, their trust-score breakdown, the review summary, the
    | home testimonials and the FAQ were all published with no entry describing
    | them, and adding a field to any of those five shapes needed no decision
    | from anyone. Writing them down is the point of the allowlist.
    */

    /** The teacher's own counters, nested under `stats` on their detail page. */
    public const TEACHER_STATS = [
        'students_taught',
        'completed_sessions',
        'response_rate',
        // ⚠️ The TEACHER's attendance — the share of countable sessions actually
        // delivered — never their students'. The name reads the other way.
        'attendance_rate',
    ];

    /** The trust score broken into its components (FR-024). */
    public const TRUST_FACTORS = [
        'student_rating',
        'punctuality',
        'completion',
        'tenure',
        'complaints_penalty',
    ];

    /** The review block: a headline number, a histogram, and the reviews. */
    public const REVIEW_SUMMARY = ['average', 'distribution', 'items'];

    /*
    | ⚠️ THERE IS NO `TESTIMONIAL` CONSTANT, AND THAT IS THE DESIGN.
    |
    | The home page used to publish a `{name, role, quote, photo_url}` shape
    | filled with three hardcoded quotes, attributed to invented people carrying
    | real Qatari family names, with nothing marking them as demonstration data.
    | PRODUCT.md's `Evidence on Hand` bans it: pre-launch, no customers, and any
    | quote on a surface is labelled demo data or does not appear.
    |
    | The home carousel now reads real reviews and publishes them through
    | `REVIEW` below — the same shape the teacher's own page uses. One shape for
    | one thing: a second constant would have let the two drift, and the field a
    | reviewer agreed to expose on the teacher page is exactly the field the home
    | page may show.
    |
    | So a quote reaching the public home page must be a row a student wrote.
    | Re-introducing an authored testimonial shape is a product decision about
    | evidence, not a layout decision, and it comes back through PRODUCT.md.
    */

    /** @var list<string> */
    public const FAQ = ['question', 'answer'];

    /** @var list<string> */
    public const TAXONOMY = ['slug', 'name_ar', 'icon', 'teachers_count'];

    /*
    | The reviewer stays a pair of initials; the TEACHER is named and pictured.
    |
    | A quote on the home page needs a face beside it or it reads as filler, and
    | the only face that may go there is the one the review is ABOUT. Publishing
    | the reviewer's photo would undo `studentDisplayName()` in a single image:
    | that method truncates the family name precisely so a reviewer cannot be
    | identified to the teacher they just rated (FR-021), and a headshot beside
    | the truncation identifies them completely.
    |
    | The teacher's name and photo are already published on their own card and
    | profile, so nothing new is exposed — the review simply says who it is for.
    */
    /** @var list<string> */
    public const REVIEW = [
        'student_display_name',
        'rating',
        'comment',
        'created_at',
        'teacher_uuid',
        'teacher_name',
        'teacher_photo_url',
    ];

    /** @var list<string> */
    public const AVAILABILITY = ['day_of_week', 'start_time', 'end_time'];

    /** @var list<string> */
    public const STATS = ['students', 'teachers', 'sessions', 'satisfaction_rate'];
}
