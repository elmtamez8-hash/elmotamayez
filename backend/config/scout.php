<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Scout — published for one setting (spec 013)
|--------------------------------------------------------------------------
|
| ⚠️ THIS FILE DID NOT EXIST, SO `queue` DEFAULTED TO `false` — every index
| write was a synchronous network call inside whatever request or job triggered
| it. That was survivable while the only writers were a teacher saving one
| question at a time. It stops being survivable in this phase: erasure and
| offboarding call `unsearchable()` over a whole workspace, and a teacher with
| two thousand questions in their bank would make two thousand blocking calls
| inside one job.
|
| Everything else here is Scout's own default, restated only because publishing
| the file is what makes the one line above take effect.
*/

return [

    'driver' => env('SCOUT_DRIVER', 'algolia'),

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    | ⚠️ THE LINE THIS FILE EXISTS FOR. With it false, `Model::unsearchable()` on
    | a builder still issues one synchronous request per batch to Meilisearch,
    | inside the caller. The tests run with `SCOUT_DRIVER=null`, so no local run
    | ever sees the cost — the same blindness that hid the missing token path.
    */
    'queue' => env('SCOUT_QUEUE', true),

    /*
    | ⚠️ TRUE BECAUSE `queue` IS TRUE, and Scout's own default of false assumes
    | the opposite. Queued, an index write dispatched inside a transaction can be
    | picked up by a worker BEFORE the transaction commits — or after it rolls
    | back, which leaves a row in the search index that does not exist in MySQL.
    | That is `FR-023` from the other direction, and `SCOUT_DRIVER=null` in the
    | test suite means no local run would ever show it.
    */
    'after_commit' => true,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    'soft_delete' => false,

    'identify' => env('SCOUT_IDENTIFY', false),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
    ],
];
