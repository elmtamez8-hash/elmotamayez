<?php

declare(strict_types=1);

return [
    /*
    | The fallback for `platform_settings › analytics.min_reviews` (spec 011 ·
    | FR-041): the minimum number of reviews a teacher needs before appearing on
    | the «الأعلى تقييماً» board at all. Five is small enough that a real teacher
    | clears it in a term and large enough that one enthusiastic parent cannot put
    | somebody at the top of the platform.
    |
    | The row is what an operator edits; this is what an unseeded database reads.
    */
    'min_reviews' => (int) env('ANALYTICS_MIN_REVIEWS', 5),
];
