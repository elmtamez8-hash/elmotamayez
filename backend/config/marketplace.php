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

    'pagination' => [
        'default_per_page' => 12,
        'max_per_page' => 48,
    ],

];
