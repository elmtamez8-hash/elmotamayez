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
        // The public URL segment. `uuid` stays: it is still the key every
        // write endpoint takes, and the old profile URLs still resolve by it.
        'slug',
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
        'intro_video_url',
    ];

    /** @var list<string> */
    public const COURSE_CARD = [
        'uuid',
        // The card's link target since `/courses/{slug}` (027). The uuid stays:
        // it is what `/subscribe?course=` and every authenticated screen speak,
        // and dropping it here would move that cost onto a second read.
        'slug',
        'title',
        'cover_url',
        'teacher',
        'type',
        'lessons_count',
        'duration_seconds',
        'price_minor',
        'price_before_discount_minor',
        'currency',
        'average_rating',
        'enrolled_count',
        'is_bestseller',
    ];

    /*
    | Spec 023 · T005 — the course's OWN page, which is a different surface from
    | the card in a list.
    |
    | ⚠️ AND IT CARRIES THE PRICE, WHICH THE CARD DELIBERATELY DOES NOT.
    | 006 · FR-021هـ took the price off browsing surfaces and left it on «the
    | buyable unit's own page» — `PublicCourseCardResource` says so beside the
    | field it dropped. Until 023 that page did not exist, so the rule had only
    | its negative half implemented. Publishing the price here is what completes
    | it, not an exception to it: a detail page showing LESS than the card it was
    | opened from is the same defect read backwards.
    |
    | 023 · FR-028 («no amount anywhere») is about the CREDIT path — a credit's
    | price is the teacher's approved settlement rate plus two platform
    | constants, so a total shown to either side is solvable for the other's
    | rate. A one-off course total is tied to no settlement rate by any equation,
    | which is exactly why `price_minor` is not in FORBIDDEN while `hourly_rate`
    | is.
    */
    /** @var list<string> */
    public const COURSE_DETAIL = [
        'uuid',
        'slug',
        'title',
        'description',
        'cover_url',
        /*
        | The promo video's ID on the teacher's own channel (018 · FR-006).
        |
        | Published ONLY when the review approved it — the Resource asks
        | `hasApprovedPromoVideo()`, which is the one spelling of that question.
        | An ID here is not a secret: the video is public on a public channel,
        | and it is the teacher's own. What it is not is a URL — see the
        | Resource for why the embed address stays out of the contract.
        */
        'promo_video_id',
        'subject',
        'grade_level',
        'teacher',
        'type',
        'lessons_count',
        'duration_seconds',
        'price_minor',
        'currency',
        'average_rating',
        'enrolled_count',
        'curriculum',
        'cohorts',
        /*
        | How long a private hour in this course lasts (023 · FR-016أ). A
        | DURATION, never a price: the student reads it in the request form and
        | does not choose it, and a form that had to ask a second endpoint for it
        | would be one round trip away from showing the wrong number.
        */
        'private_session_minutes',
        /*
        | Whether the private-subscription invitation may be drawn (027 · FR-003).
        | ONE boolean, and it leaks nothing: «no plan», «switched off» and
        | «awaiting a price» all answer false, exactly as `PurchaseSubscription`
        | collapses the three into one sentence so that nobody learns which
        | teachers have a plan waiting to be priced.
        */
        'private_subscription_available',
    ];

    /*
    | The author, as the course page shows them (FR-004) — name, face, trust and
    | a way through to their page.
    |
    | A SEPARATE constant from the card's byline, and the extra key is why:
    | `trust_score` is published on the teacher's own card already, so it exposes
    | nothing new, but adding it to the byline shape would put it on every course
    | card in every list too — a decision nobody made.
    */
    /** @var list<string> */
    public const COURSE_TEACHER = [
        'uuid',
        'slug',
        'name',
        'photo_url',
        'trust_score',
        'trust_score_band',
    ];

    /*
    | The published tree, as a visitor who has not bought it may read it.
    |
    | ⚠️ NO MEDIA PATH, AND NO `uuid` EXCEPT ON ONE KIND OF ITEM. A lesson uuid
    | in a public payload is an invitation to try it against the playback
    | endpoint — and that reading is CORRECT and was measured again in 032:
    | `PlaybackController` resolves the lesson with `withoutWorkspaceScope()` and
    | `IssuePlaybackGrant::mayWatch()` answers yes to ANY signed-in account for
    | ANY open lesson, across every workspace. Publishing the uuid of an open
    | UPLOADED video would therefore hand every preview video on the platform to
    | one free account.
    |
    | ⛔ SPEC 032 IS A DECLARED AMENDMENT TO 023 · FR-005/SC-004, NARROWED TO THE
    | ONE CASE WHERE THAT SENTENCE DOES NOT APPLY: an `embed` item has NO MEDIA
    | ASSET AT ALL, so its uuid opens no bytes at that endpoint — there is
    | nothing there to open. `CURRICULUM_ITEM` therefore carries `uuid` and
    | `is_open`, and the resource fills them for `isPubliclyReadable()` alone —
    | never for every open lesson. Both keys are ABSENT on every other item, not
    | null.
    */
    /** @var list<string> */
    public const CURRICULUM_SECTION = ['title', 'chapters'];

    /*
    | Three levels, not two. `contracts/public-course.md` sketched sections
    | holding items directly — FR-005 names «الأقسامَ والفصولَ وعناوينَ الدروسِ»,
    | and `lessons.chapter_id` is NOT NULL, so every lesson has a chapter and
    | flattening one away would show the visitor a shape the teacher never built.
    */
    /** @var list<string> */
    public const CURRICULUM_CHAPTER = ['title', 'items'];

    /** @var list<string> */
    public const CURRICULUM_ITEM = ['title', 'kind', 'duration_seconds', 'uuid', 'is_open'];

    /*
    | Spec 032 · FR-010 — the open embedded lesson, read by a visitor with no
    | account.
    |
    | What is needed to WATCH it and nothing else: no publication status, no
    | price, no progress denominator, nothing about the rest of the tree.
    |
    | ⚠️ `duration_seconds` IS OMITTED ENTIRELY WHEN IT IS ZERO, never sent as
    | zero or null. The column defaults to 0 and the teacher writes it by hand,
    | so a zero means «not written» — and «٠ دقيقة» is a lie rather than a blank.
    */
    /** @var list<string> */
    public const PREVIEW_LESSON = ['uuid', 'title', 'kind', 'duration_seconds', 'embed_url', 'course'];

    /*
    | ⚠️ ITS OWN CONSTANT, AND NOT A LUXURY. `PublicExposureTest` flattens keys
    | and matches names at any depth, so a nested object with no constant of its
    | own is one where a third key can be added later with nobody deciding — the
    | shape five payloads already drifted into.
    |
    | `slug` is here because the enrolment invitation beside the video (FR-013)
    | builds the course's public url from it.
    */
    /** @var list<string> */
    public const PREVIEW_LESSON_COURSE = ['uuid', 'title', 'slug'];

    /*
    | A group as the public sees it (FR-010 · FR-014).
    |
    | ⚠️ NOT ONE FIELD ABOUT THE MEMBERS — no names, no photos, no
    | `members_count`. The raw count is subtracted from the capacity ON THE
    | SERVER, so the two halves of that subtraction never both reach a browser.
    | And `seats_left` is ABSENT rather than zero for a group with no declared
    | ceiling: «unlimited» is not a number, and a zero there reads as «full».
    */
    /*
    | ⚠️ `schedule` IS A LIST OF ARABIC LABELS, NOT A LIST OF OBJECTS.
    | `contracts/public-course.md` sketched `{day_of_week, start_time,
    | end_time}` — and `CohortScheduleDirectory` has answered «when does this
    | group meet» since 021, in exactly the short labels the student's own group
    | picker renders. A second shape here would be a second derivation of one
    | fact, and the day a session status is added the marketplace advertises a
    | time the picker does not show. So there is no COHORT_SLOT constant: the
    | slots are strings.
    */
    /** @var list<string> */
    /*
    | ⚠️ `is_joinable` IS THE SERVER'S OWN VERDICT AND NOT A REPEAT OF `status`
    | (027 · FR-002). It is derived by `Cohort::isJoinable()`, the same predicate
    | the booking door reads — so the card cannot say yes while the door says no.
    | Deriving it in the browser from `status` and `seats_left` is the two-
    | spellings defect, and it publishes nothing `status` does not already.
    */
    public const COHORT = ['uuid', 'name', 'description', 'status', 'schedule', 'seats_left', 'is_joinable'];

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
    public const TAXONOMY = ['slug', 'name', 'icon', 'teachers_count'];

    /*
    | The signup reads (spec 022 · FR-002) — a SEPARATE constant, not three keys
    | added to the one above.
    |
    | Widening `TAXONOMY` would make `grade_level_slug` publishable on the
    | MARKETPLACE payload too, where it means nothing and where the subjects
    | offered at a stage are derived from the teachers rather than stored. And
    | `teachers_count` has no business on a signup form: the answer there is the
    | whole vocabulary, so a count of zero is the normal case and printing it
    | beside an option reads as a warning against picking it.
    */
    /** @var list<string> */
    public const SIGNUP_TAXONOMY = ['slug', 'name', 'grade_level_slug'];

    /*
    | TWO faces are publishable on a review, and they are not the same decision.
    |
    | `teacher_*` — the teacher the review is ABOUT. Their name and photo are
    | already published on their own card and profile, so a quote carrying them
    | exposes nothing new; it only says who the quote is for. The home carousel
    | uses these.
    |
    | `student_avatar_url` — the REVIEWER's own photo, and this one is a
    | deliberate trade-off the product owner made, not a default:
    |
    |   `Review::studentDisplayName()` truncates the family name — "أحمد م." —
    |   precisely so a teacher cannot identify who rated them (FR-021). A face
    |   beside that truncation identifies them completely. The truncation is not
    |   thereby wrong: it still keeps the name off the page, and a student who
    |   uploads no avatar is unaffected. But nobody should re-derive the reason
    |   this field is here from the field itself.
    |
    | It reaches ONE surface today: the teacher's own reviews tab, where the
    | owner asked for it. The home carousel deliberately does not send it —
    | a quote on the marketplace front page is read by people with no relation
    | to either party. A third surface that wants this field makes the decision
    | again, out loud, rather than inheriting it from this list.
    */
    /** @var list<string> */
    public const REVIEW = [
        'student_display_name',
        'student_avatar_url',
        'rating',
        'comment',
        'created_at',
        'teacher_slug',
        'teacher_name',
        'teacher_photo_url',
    ];

    /** @var list<string> */
    public const AVAILABILITY = ['day_of_week', 'start_time', 'end_time'];

    /** @var list<string> */
    public const STATS = ['students', 'teachers', 'sessions', 'satisfaction_rate'];

    /**
     * `GET /platform` — the product's own name, and nothing else.
     *
     * ⚠️ ONE FIELD, AND THE LIST IS WHAT KEEPS IT ONE. `platform_settings` holds
     * the device limit, the grant TTL, the operating fee and the gateway's basis
     * points; an endpoint that answered with the map would put the platform's
     * half of the price on a public URL, and every key added to that table
     * afterwards would join it silently.
     */
    public const PLATFORM_IDENTITY = ['name'];
}
