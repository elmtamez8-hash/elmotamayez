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
        'hourly_rate',
        'currency',
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

    /** @var list<string> */
    public const TAXONOMY = ['slug', 'name_ar', 'icon', 'teachers_count'];

    /** @var list<string> */
    public const REVIEW = ['student_display_name', 'rating', 'comment', 'created_at'];

    /** @var list<string> */
    public const AVAILABILITY = ['day_of_week', 'start_time', 'end_time'];

    /** @var list<string> */
    public const STATS = ['students', 'teachers', 'sessions', 'satisfaction_rate'];
}
