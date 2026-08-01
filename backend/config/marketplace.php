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
        'weights' => [
            'student_rating' => 35,
            'punctuality' => 25,
            'completion' => 25,
            'tenure' => 15,
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
    |--------------------------------------------------------------------------
    | Platform workspace
    |--------------------------------------------------------------------------
    |
    | Home for teachers who applied directly rather than through an academy
    | (FR-013). Created on first use; see Support\PlatformWorkspace.
    |
    */

    'platform_workspace' => [
        'slug' => env('MARKETPLACE_PLATFORM_WORKSPACE', 'platform'),
        'name' => 'المنصة',
    ],

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
        'default_per_page' => 12,
        'max_per_page' => 48,
    ],

];
