<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trust Score
    |--------------------------------------------------------------------------
    |
    | Weights must sum to 100. The complaint penalty is subtracted after the
    | weighted sum. A teacher below either minimum is "building" (null score),
    | never zero — a new teacher is not an untrustworthy one.
    |
    */

    'trust_score' => [
        // `responsiveness` is the response rate (owner decision 2026-10-11). A
        // teacher with no rate yet is scored on the other four, re-weighted — a
        // missing figure is never read as zero.
        'weights' => [
            'student_rating' => 30,
            'punctuality' => 25,
            'completion' => 20,
            'tenure' => 15,
            'responsiveness' => 10,
        ],

        'complaint_penalty_per_item' => 5,
        'complaint_penalty_max' => 20,

        'tenure_saturation_months' => 12,

        'minimum_sessions' => 10,
        'minimum_reviews' => 3,

        // Lower bound of each band. Anything below 'medium' is low.
        'bands' => [
            'high' => 80,
            'medium' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public listing cache
    |--------------------------------------------------------------------------
    |
    | 60 seconds is derived from SC-010 (a status change must reach the public
    | marketplace within a minute), not picked arbitrarily. Raising it breaks
    | that success criterion.
    |
    */

    'cache_ttl_seconds' => 60,

    /*
    | Working days the academic team is given to review an application. Shown to
    | the applicant on the confirmation screen, so it is a promise, not a hint.
    */
    'review_days' => 3,

    /*
    | Enrolments a course needs before it earns the "الأكثر طلباً" badge. Low
    | enough to be reachable at launch, high enough that the badge still means
    | something once it is.
    */
    'bestseller_enrollments' => 25,

    'pagination' => [
        // The DEFAULT page size lives in `platform_settings` now
        // (`ListingPageSizes`, «إعدادات المنصّة»); this is only the ceiling.
        'max_per_page' => 48,
    ],

];
