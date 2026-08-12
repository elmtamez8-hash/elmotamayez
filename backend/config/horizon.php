<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,

        /*
        | ⚠️ A NEW QUEUE WITH NO ENTRY HERE NEVER FIRES LongWaitDetected AT ALL —
        | the thresholds are per connection/queue pair, and a pair that is absent
        | is not watched at a default, it is not watched. A backed-up payments
        | queue would then tell nobody, while every provider callback in it had
        | already been answered `202`: we said we had it, and the queue quietly
        | said otherwise.
        |
        | Half a minute, not the sixty seconds `default` gets. A student is on
        | the payment result screen while this drains.
        */
        'redis:payments' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            // Order is priority order. `notifications-high` carries the mandatory
            // types (security, financial); a thousand queued reminders must not
            // make a security alert wait behind them.
            'queue' => ['notifications-high', 'default', 'notifications', 'certificates'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        /*
        | The nightly sweeps, on their own workers (spec 006).
        |
        | ⚠️ SEPARATE BECAUSE OF THE TIMEOUT, NOT ONLY THE PRIORITY. Every sweep
        | above walks the whole platform, and supervisor-1 kills a job at 60
        | seconds — so a reconciliation that grew past a minute would be killed,
        | retried by the scheduler the next night, and killed again, silently,
        | for as long as the platform kept growing. `tries` stays at 1 for the
        | same reason it is 1 above: these are idempotent by their unique keys,
        | but a retry storm on a sweep is a second full walk, not a fix.
        |
        | One process, because every one of them carries `withoutOverlapping()`
        | — a second worker would only ever be waiting on a lock.
        |
        | ⚠️ AND IT IS LISTED IN `environments` BELOW, NOT ONLY HERE. `defaults`
        | supplies shared VALUES; `environments` is what decides which
        | supervisors actually run. A supervisor defined only in defaults is a
        | queue with no worker — the jobs enqueue, nothing drains them, and
        | nothing anywhere says so.
        */
        'supervisor-maintenance' => [
            'connection' => 'redis',
            'queue' => ['maintenance'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 900,
            // Below the web workers: a sweep must never make a student's charge
            // or a security alert wait for CPU.
            'nice' => 10,
        ],

        /*
        | Provider callbacks and payment side effects, on their own workers
        | (spec 007).
        |
        | ⚠️ SEPARATE BECAUSE OF WHO IS WAITING, NOT BECAUSE OF THE WORKLOAD. A
        | callback is answered `202` before it is processed, so from the
        | gateway's side the payment is already settled — every second this
        | queue spends behind a bulk notification run is a second the student
        | watches a result screen that our own API has already promised. It is
        | also why the timeout is the shortest on the platform: work here is one
        | transaction against one row, and a payment job still running after
        | thirty seconds is stuck, not slow.
        |
        | `tries` is 1 for the reason it is 1 above — these jobs are idempotent
        | by their unique keys, and the deferred-callback job declares its own
        | twelve attempts and backoff from `config/payments.php`, which is the
        | one retry policy in this module that is a decision rather than an
        | accident.
        |
        | ⚠️ AND IT IS LISTED IN `environments` BELOW, NOT ONLY HERE — see the
        | note on supervisor-maintenance. A supervisor defined only in defaults
        | is a queue with no worker, and nothing anywhere says so.
        */
        'supervisor-payments' => [
            'connection' => 'redis',
            'queue' => ['payments'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 30,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],

            'supervisor-maintenance' => [
                'maxProcesses' => 1,
            ],

            // Scales with traffic, unlike the sweeps: these arrive when students
            // pay, not on a schedule.
            'supervisor-payments' => [
                'maxProcesses' => 3,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],

            'supervisor-maintenance' => [
                'maxProcesses' => 1,
            ],

            'supervisor-payments' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
